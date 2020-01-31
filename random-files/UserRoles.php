<?php

namespace App\Http\Traits;

use App\Jobs\NotificationPrimaryRole;
use App\User;
use App\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Fansided\MicroservicesAuth\Auth As AuthUser;

trait UserRoles {

	/**
	 * @param Request $request
	 * @param User    $user
	 *
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function addRole(Request $request, User $user)
	{
		$validator = \Validator::make($request->only([
			'roles',
			'sites'
		]), [
			'roles'   => 'array|required',
			'roles.*' => 'integer',
			'sites'   => 'array|required',
			'sites.*' => 'integer'
		]);

		if ($validator->fails()) {
			return response()->failure([
				'message' => 'Validation Failure',
				'messages' => $validator->messages()
			], 422);
		}

		$response = [
			'added' => [],
			'errors' => []
		];

		foreach ($request->get('roles') as $roleId) {
			foreach ($request->get('sites') as $siteId) {

                $userRoleArray = [
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'site_id' => $siteId
                ];

			    // Permission Checking
                $canUserAddDeleteRole = $this->canUserAddOrDeleteRole($roleId);

                if ($canUserAddDeleteRole === false) {
                    $response['errors'][] = array_merge(
                        $userRoleArray,
                        ['error' => 'You do not have permission to add that role to this user.']
                    );
                    continue;
                }

				try {

                     if (env('APP_ENV') !== 'local') {
                         if ( ( $added = $user->addToBlog( $siteId ) ) && $added->status !== 'OK' ) {
                             throw new \Exception( $added->message );
                         }
                     }

					if($request->get('type') == 'primary'){
						$currentPrimary = $user->primaryRoleOnSite( $siteId );
						if($currentPrimary){
							$currentPrimary->delete();
						}
					}

					$userRole = UserRole::create($userRoleArray);

					if($request->get('type') == 'primary'){
						// When a user gets a new primary role, check if there are any pending notifications queued up
						if ( UserRole::join( 'roles', 'user_roles.role_id', 'roles.id' )
						             ->where( 'user_roles.user_id', '=', $user->id )
						             ->where( 'user_roles.notified', '=', 0 )
						             ->where( 'roles.type', '=', 'primary' )
						             ->count( 'user_roles.id' ) === 1 ) {

							// Queue up email notification
							NotificationPrimaryRole::dispatch( $user )->delay( now()->addMinutes( 5 ) );
						}
					}


					$role = $userRole->role->only(['type','name','default_title']);
					$userRole = $userRole->toArray();
					$userRole['type'] = $role['type'];
					unset($userRole['role']);

					if($role['type'] == 'primary'){
						if ( ! empty( $role['default_title'] ) ) {
							$userRole['title'] = $role['default_title'];
						} else {
							$userRole['title'] = $role['name'];
						}
						$response['added'][] = $userRole;
					}else {
						$response['added'][] = $userRole;
					}

					$user->updateWordPressPermissions( $siteId );

				} catch(QueryException $e) {
                    $response['errors'][] = array_merge(
                        $userRoleArray,
                        ['error' => $this->getErrorMessage($e->getCode())]
                    );
				}
			}
		}

		return response()->success($response);
	}

	/**
	 * @param Request $request
	 * @param User    $user
	 *
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function deleteRole(Request $request, User $user)
	{
		$validator = \Validator::make($request->only([
			'roles',
			'sites'
		]), [
			'roles'   => 'array|required',
			'roles.*' => 'integer',
			'sites'   => 'array|required',
			'sites.*' => 'integer'
		]);

		if ($validator->fails()) {
			return response()->failure([
				'message' => 'Validation Failure',
				'messages' => $validator->messages()
			], 422);
		}

		$response = [
			'deleted' => [],
			'errors' => []
		];

		foreach ($request->get('roles') as $roleId) {
			foreach ($request->get('sites') as $siteId) {

				$userRoleArray = [
					'user_id' => $user->id,
					'role_id' => $roleId,
					'site_id' => $siteId
				];

                // Permission Checking
                $canUserAddDeleteRole = $this->canUserAddOrDeleteRole($roleId);

                if ($canUserAddDeleteRole === false) {
                    $response['errors'][] = array_merge(
                        $userRoleArray,
                        ['error' => 'You do not have permission to delete that role to this user.']
                    );
                    continue;
                }

				try {
					$userRole = UserRole::where($userRoleArray)->firstOrFail();
				} catch (\Exception $e) {
					$response['errors'][] = array_merge(
						$userRoleArray,
						['error' => 'The user does not have that role on that site.']
					);
					continue;
				}

				try {
					$userRole->delete();
				} catch (\Exception $e) {
					$response['errors'][] = array_merge(
						$userRoleArray,
						['error' => 'Role could not be deleted']
					);
					continue;
				}

				$response['deleted'][] = $userRoleArray;
				$user->updateWordPressPermissions( $siteId );

			}
		}

		return response()->success($response);
	}

    /**
     * Role assignment permission checks.
     *
     * @param int $roleId
     *
     * @return boolean
     */
    private function canUserAddOrDeleteRole(int $roleId)
    {
        switch ($roleId) {
            case 1:
                if (!AuthUser::user()->can('GRANT_PUBLISHER_ROLE')) {
                    return false;
                }
                break;

            case 2:
                if (!AuthUser::user()->can('GRANT_ASSISTANT_EDITOR_ROLE')) {
                    return false;
                }
                break;

            case 3:
                if (!AuthUser::user()->can('GRANT_LAYOUT_MANAGER_ROLE')) {
                    return false;
                }
                break;

            case 4:
                if (!AuthUser::user()->can('GRANT_FANDOM_250_EDITOR_ROLE')) {
                    return false;
                }
                break;

            case 5:
                if (!AuthUser::user()->can('GRANT_EDIT_FLOW_MANAGER_ROLE')) {
                    return false;
                }
                break;

            case 6:
                if (!AuthUser::user()->can('GRANT_INVOICE_MODERATOR_ROLE')) {
                    return false;
                }
                break;

            case 7:
                if (!AuthUser::user()->can('GRANT_FLAT_RATE_MODERATOR_ROLE')) {
                    return false;
                }
                break;

            case 8:
                if (!AuthUser::user()->can('GRANT_FREELANCE_MANAGER_ROLE')) {
                    return false;
                }
                break;

            case 9:
                if (!AuthUser::user()->can('GRANT_READER_ROLE')) {
                    return false;
                }
                break;

            case 10:
                if (!AuthUser::user()->can('GRANT_FREELANCER_ROLE')) {
                    return false;
                }
                break;

            case 11:
                if (!AuthUser::user()->can('GRANT_WRITER_ROLE')) {
                    return false;
                }
                break;

            case 12:
                if (!AuthUser::user()->can('GRANT_CONTRIBUTOR_ROLE')) {
                    return false;
                }
                break;

            case 13:
                if (!AuthUser::user()->can('GRANT_SITE_EXPERT_ROLE')) {
                    return false;
                }
                break;

            case 14:
                if (!AuthUser::user()->can('GRANT_STAFF_EDITOR_ROLE')) {
                    return false;
                }
                break;

            case 15:
                if (!AuthUser::user()->can('GRANT_DIRECTOR_ROLE')) {
                    return false;
                }
                break;

            case 16:
                if (!AuthUser::user()->can('GRANT_EDITOR_IN_CHIEF_ROLE')) {
                    return false;
                }
                break;

            case 17:
                if (!AuthUser::user()->can('GRANT_OPS_TEAM_MEMBER_ROLE')) {
                    return false;
                }
                break;

            case 18:
                if (!AuthUser::user()->can('GRANT_CEO_ROLE')) {
                    return false;
                }
                break;

            case 19:
                if (!AuthUser::user()->can('GRANT_PRODUCT_TEAM_MEMBER_ROLE')) {
                    return false;
                }
                break;

            case 20:
                if (!AuthUser::user()->can('GRANT_SENIOR_PRODUCT_TEAM_MEMBER_ROLE')) {
                    return false;
                }
                break;

            case 21:
                if (!AuthUser::user()->can('GRANT_ENGINEER_ROLE')) {
                    return false;
                }
                break;
        }

        return true;
    }
}