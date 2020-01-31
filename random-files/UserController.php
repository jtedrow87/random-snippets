<?php

namespace App\Http\Controllers;

use App\Http\Traits\TwoFactorAuth;
use App\Http\Traits\UserCustoms;
use App\Http\Traits\UserPermissions;
use App\Http\Traits\UserRoles;
use App\Http\Traits\UserTitles;

use App\Role;
use App\Rules\PasswordRestrictionsCommon;
use App\Rules\PasswordRestrictionsUser;
use App\Rules\PasswordRestrictionsPrevious;
use App\User;
use App\UserRole;
use Fansided\MicroservicesAuth\Auth As AuthUser;
use App\UserDetails;
use App\PasswordHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;


/**
 * Class UserController
 *
 * @package App\Http\Controllers
 */
class UserController extends Controller
{

	use TwoFactorAuth, UserPermissions, UserRoles, UserTitles, UserCustoms;

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Check permissions
        if (!AuthUser::user()->can('CAN_MANAGE_USERS')) {
            return response()->invalidPermissions();
        }

        $availableParams = $request->only([
            'siteId',
            'permissionId',
            'perPage',
            'firstName',
            'email',
            'status',
            'id',
            'createdAt',
	        'search',
            'roles',
            'start',
            'end'
        ]);

        $validator = \Validator::make($availableParams, [
            'siteId'       => 'int',
            'permissionId' => 'int',
            'perPage'      => 'int',
            'firstName'    => [
                'string',
                Rule::in(['asc', 'desc']),
            ],
            'email'        => [
                'string',
                Rule::in(['asc', 'desc']),
            ],
            'status'       => 'string',
            'id'           => [
                'string',
                Rule::in(['asc', 'desc']),
            ],
            'createdAt'    => [
                'string',
                Rule::in(['asc', 'desc'])
            ],
            'search'       => 'nullable|string',
            'roles'        => 'array',
            'roles.*'      => 'integer',
            'start'        => 'required_with:end|date|before_or_equal:end',
            'end'          => 'required_with:start|date|after_or_equal:start',
        ]);

        if ($validator->fails()) {
            return response()->failure([
                'message'  => 'Validation failure.',
                'messages' => $validator->messages()
            ], 422);
        }

        $siteId       = $availableParams['siteId'] ?? null;
        $permissionId = $availableParams['permissionId'] ?? null;
        $status       = $availableParams['status'] ?? null;
        $roles        = $availableParams['roles'] ?? null;
        $start        = $availableParams['start'] ?? null;
        $end          = $availableParams['end'] ?? null;

        $queryParams = array_filter($availableParams);

        if ($siteId || $permissionId || $roles ) {

            $users = User::whereHas('userRoles', function ($query) use ($siteId, $permissionId, $roles, $status) {
                if ($siteId) {
                    $query->where('site_id', '=', $siteId);
                }

                if ($permissionId) {
                    $query->whereHas('role.rolePermissions', function ($query) use ($permissionId) {
                        $query->where('permission_id', '=', $permissionId);
                    });
                }
            })->with('userRoles');

            if ($roles) {
                $users->whereHas('userRoles', function ($query) use ($roles) {
                    $query->select(\DB::raw('count(distinct role_id)'))->whereIn('role_id', $roles);
                }, '=', count($roles));
            }

        } else {
            $users = User::with('userRoles');
        }

        // Return users created between certain dates
        if ($start && $end) {
            $start = $start . " 00:00:00";
            $end = $end . " 23:59:59";
            $users->whereBetween('created_at', [$start, $end]);
        }

        /**  Return users by status
        /*   All - includes users with deleted_at values
        /*   Inactive - Returns only those with deleted_at values
        */
        switch ($status) {
            case 'all':
                $users = $users->withTrashed();
                break;

            case 'inactive':
                $users = $users->onlyTrashed();
                break;
            default:
                break;
        }

	    if ( ! empty( $queryParams['search'] ) ) {
		    $searchTerm = filter_var(
			    addslashes( strip_tags( $queryParams['search'] ) )
			    , FILTER_SANITIZE_STRING );
		    if ( is_numeric( $searchTerm ) ) {
			    $users->where( 'id', $searchTerm );
		    } else {
			    $users->whereRaw( "MATCH(users.first_name, users.last_name, users.email) AGAINST ('$searchTerm')" );
		    }
	    }

        if (isset($queryParams['firstName'])) {
            $users->orderBy('first_name', $queryParams['firstName']);
        }
        if (isset($queryParams['email'])) {
            $users->orderBy('email', $queryParams['email']);
        }
        if (isset($queryParams['id'])) {
            $users->orderBy('id', $queryParams['id']);
        }
        if (isset($queryParams['createdAt'])) {
            $users->orderBy('created_at', $queryParams['createdAt']);
        }

        $perPage = $request->get('perPage');
        $perPage = ($perPage) ? $perPage : 15;

        $paginator = $users->paginate($perPage);

        $paginator = $paginator->toArray();

        if ($paginator['total'] === 0) {
            return \response()->success([
                'message' => 'No users match the search criteria.',
            ], 200);
        }

        $paginator['first_page_url'] .= '&' . http_build_query($queryParams);
        $paginator['last_page_url'] .= '&' . http_build_query($queryParams);

        if (isset($paginator['next_page_url'])) {
            $paginator['next_page_url'] .= '&' . http_build_query($queryParams);
        }

        if (isset($paginator['prev_page_url'])) {
            $paginator['prev_page_url'] .= '&' . http_build_query($queryParams);
        }

        $meta = $paginator;
        unset($meta['data']);

        return \response()->success(
            ['users' => $paginator['data']],
            200,
            [],
            $meta
        );
    }

    /**
     * Add a new user to the database.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
	    // Check permissions
	    if (!AuthUser::user()->can('CAN_CREATE_USER')) {
		    return response()->invalidPermissions();
	    }

        $messages = [
            'password.regex' => 'The password must contain at least one lowercase letter, one uppercase letter, and one number.',
        ];

        $validator = \Validator::make($request->only([
            'firstName',
            'lastName',
            'email',
            'password',
            'displayName',
            'bio',
            'profilePic',
            'streetAddress1',
            'streetAddress2',
            'city',
            'state',
            'zip',
            'country',
            'twitter',
            'facebook',
            'instagram',
            'youtube',
            'twitch',
            'linkedin',
        ]), [
            'firstName'      => 'string|required',
            'lastName'       => 'string|required',
            'email'          => 'email|unique:users,email|required',
            'password'       => [
                'string',
                'min:12',
                'regex:/[a-z]/', // must contain at least one lowercase letter
                'regex:/[A-Z]/', // must contain at least one uppercase letter
                'regex:/[0-9]/', // must contain at least one number
                new PasswordRestrictionsCommon(),
                new PasswordRestrictionsUser($user = new User([
                    'first_name' => $request->get('firstName'),
                    'last_name' => $request->get('lastName'),
                    'email' => $request->get('email'),
                ])),
            ],
            'displayName'    => 'string|max:155',
            'bio'            => 'string',
            'profilePic'     => 'string',
            'streetAddress1' => 'string|max:155',
            'streetAddress2' => 'string|max:155',
            'city'           => 'string|max:155',
            'state'          => 'string|max:155',
            'zip'            => 'string|max:20',
            'country'        => 'string|max:155',
            'twitter'        => 'string|max:155',
            'facebook'       => 'string|max:155',
            'instagram'      => 'string|max:155',
            'youtube'        => 'string|max:155',
            'twitch'         => 'string|max:155',
            'linkedin'       => 'string|max:155',
        ], $messages);

        if ($validator->fails()) {
            return response()->failure([
                'message' => 'Validation Failure',
                'messages' => $validator->messages()
            ], 422);
        }

        try {
			if(empty($password = $request->get('password'))) {
				// No password? No problem...
				$password = [];
				$alpha   = range( 'a', 'z' );
				$special = str_split( '!@#$%^&*)(' );
				$length = 35;

				for($x = 0; $x < $length; $x++){
					$char = $alpha[rand(0,count($alpha)-1)];
					$password[] = rand(0,1) ? $char : strtoupper($char);
				}
				$randomSpecial = [
					rand(0,19),
					rand(20,29),
					rand(30,35),
					rand(20,29),
					rand(30,35),
				];
				$randomNumeric = [
					rand(0,4),
					rand(5,9),
					rand(10,14),
					rand(15,19),
					rand(20,24),
					rand(25,29),
					rand(30,35),
				];
				foreach($randomNumeric as $numericSpot){
					$password[$numericSpot] = rand(0,9);
				}
				foreach($randomSpecial as $specialSpot){
					$password[$specialSpot] = $special[rand(0,count($special)-1)];
				}
				$password = implode($password);
			}

	        $user->password = \Hash::make($password);

	        // Save user to confirm user object is valid
	        if ( $user->save() && \App::environment() !== 'local' ) {

		        // Add user to WordPress and get new UserID to keep the 2 systems in sync
		        if ( ( $wpInfo = $user->addToWordPress() ) && $wpInfo->status !== 'OK' ) {
		        	$user->forceDelete(); // Delete the user, WP failed so bail out
			        return response()->failure( [
				        'message' => $wpInfo->message
			        ], 422 );
		        }

		        // Replace the users auto incremented ID with the users WP ID
		        $user->id = $wpInfo->data->userId;
		        $user->save();
			}

            $passwordHistory = new PasswordHistory([
                'password' => $user->password
            ]);

            if (!$user->previousPasswords()->save($passwordHistory)) {
                \Log::error('New password was not added to password store.',
                    [
                        'user' => [
                            'id' => $user->id,
                        ]
                    ]
                );
            }

            try {
                $userDetail = new UserDetails([
                    'username'          => !empty($wpInfo) ? $wpInfo->data->userName : str_replace(' ', '', $user->first_name . $user->last_name),
                    'display_name'      => $request->get('displayName') ? $request->get('displayName') : str_replace(' ', '', $user->first_name . $user->last_name),
                    'bio'               => $request->get('bio'),
                    'profile_pic'       => $request->get('profilePic'),
                    'street_address_1'  => $request->get('streetAddress1'),
                    'street_address_2'  => $request->get('streetAddress2'),
                    'city'              => $request->get('city'),
                    'state'             => $request->get('state'),
                    'zip'               => $request->get('zip'),
                    'country'           => $request->get('country'),
                    'twitter'           => $request->get('twitter'),
                    'facebook'          => $request->get('facebook'),
                    'instagram'         => $request->get('instagram'),
                    'youtube'           => $request->get('youtube'),
                    'twitch'            => $request->get('twitch'),
                    'linkedin'          => $request->get('linkedin'),
                    'workmarket_id'     => 0,
                    'wm_country'        => NULL,
                    'wm_payment_method' => NULL,
                ]);

                $user->details()->save($userDetail);

	            $baseRole = Role::where( 'slug', '=', 'base' )->first( 'id' );
	            if ( ! empty( $baseRole ) ) {
		            $defaultRole          = new UserRole();
		            $defaultRole->user_id = $user->id;
		            $defaultRole->site_id = 0;
		            $defaultRole->role_id = $baseRole->id;
		            $defaultRole->save();
	            }

            } catch (QueryException $e) {
                $userDetail = [
                    'error' => 'User detail entry was not added.',
                ];
            }

        } catch (QueryException $e) {
            return response()->failure([
                'message' => 'User was not added.'
            ], 422);
        }

        $newUser = [
            'user' => [$user],
            'details' => [$userDetail],
        ];

        return response()->success($newUser);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        // Check permissions
        $requiredPermission = ($user->id === AuthUser::user()->id) ? 'CAN_VIEW_MY_PROFILE' : 'CAN_VIEW_ANY_PROFILE';

        if (!AuthUser::user()->can($requiredPermission)) {
            return response()->invalidPermissions();
        }

        $user = $user->buildKitchenSink( true );

        return response()->success(
            ['user' => $user]
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, User $user)
    {
    	    // Check permissions
    	    $requiredPermission = ($user->id === AuthUser::user()->id) ? 'CAN_UPDATE_MY_PROFILE' : 'CAN_UPDATE_ANY_PROFILE';

    	    if (!AuthUser::user()->can($requiredPermission)) {
		    return response()->invalidPermissions();
	    }

	    $validator = \Validator::make($request->only([
            'firstName',
            'lastName',
            'email',
            'displayName',
            'bio',
            'profilePic',
            'streetAddress1',
            'streetAddress2',
            'city',
            'state',
            'zip',
            'country',
            'twitter',
            'facebook',
            'instagram',
            'youtube',
            'twitch',
            'linkedin',
        ]), [
            'firstName'      => 'string',
            'lastName'       => 'string',
            'email'          => ['unique:users,email,' . $user->id],
            'displayName'    => 'string|max:155',
            'bio'            => 'string|nullable',
            'profilePic'     => 'string|nullable',
            'streetAddress1' => 'string|max:155|nullable',
            'streetAddress2' => 'string|max:155|nullable',
            'city'           => 'string|max:155|nullable',
            'state'          => 'string|max:155|nullable',
            'zip'            => 'string|max:20|nullable',
            'country'        => 'string|max:155|nullable',
            'twitter'        => 'string|max:155|nullable',
            'facebook'       => 'string|max:155|nullable',
            'instagram'      => 'string|max:155|nullable',
            'youtube'        => 'string|max:155|nullable',
            'twitch'         => 'string|max:155|nullable',
            'linkedin'       => 'string|max:155|nullable',
        ]);

        if ($validator->fails()) {
            return response()->failure(
                [
                    'message' => 'Validation Failure',
                    'messages' => $validator->messages()
                ],
                422
            );
        }

        // Create a DB entry for users without a user_details entry
        if (is_null($user->details)) {
            $userDetails = UserDetails::firstOrNew([
                'user_id'      => $user->id,
                'username'     => $user->email,
                'display_name' => str_replace(' ', '', $user->first_name . $user->last_name),
            ]);

            $userDetails->save();
        } else {
            $userDetails = $user->details;
        }

        if ($request->has('firstName')) {
            $user->first_name = $request->get('firstName');
        }
        if ($request->has('lastName')) {
            $user->last_name = $request->get('lastName');
        }
        if ($request->has('email')) {
            $user->email = $request->get('email');
            $userDetails->username = $user->email;
        }
        if ($request->has('displayName')) {
            $userDetails->display_name = $request->get('displayName');
        }
        if ($request->has('bio')) {
            $userDetails->bio = $request->get('bio');
        }
        if ($request->has('profilePic')) {
            $userDetails->profile_pic = $request->get('profilePic');
        }
        if ($request->has('streetAddress1')) {
            $userDetails->street_address_1 = $request->get('streetAddress1');
        }
        if ($request->has('streetAddress2')) {
            $userDetails->street_address_2 = $request->get('streetAddress2');
        }
        if ($request->has('city')) {
            $userDetails->city = $request->get('city');
        }
        if ($request->has('state')) {
            $userDetails->state = $request->get('state');
        }
        if ($request->has('zip')) {
            $userDetails->zip = $request->get('zip');
        }
        if ($request->has('country')) {
            $userDetails->country = $request->get('country');
        }
        if ($request->has('twitter')) {
            $userDetails->twitter = $request->get('twitter');
        }
        if ($request->has('facebook')) {
            $userDetails->facebook = $request->get('facebook');
        }
        if ($request->has('instagram')) {
            $userDetails->instagram = $request->get('instagram');
        }
        if ($request->has('youtube')) {
            $userDetails->youtube = $request->get('youtube');
        }
        if ($request->has('twitch')) {
            $userDetails->twitch = $request->get('twitch');
        }
        if ($request->has('linkedin')) {
            $userDetails->linkedin = $request->get('linkedin');
        }

        if ($user->save()) {
            $changed = $user->getChanges();
            $updated = true;
        }

        if ($user->details()->save($userDetails)) {
        	$changed += empty($user->details) ? $userDetails->toArray() : $user->details->getChanges();
            $updated = true;
        }

        if (empty($updated)) {
            return response()->failure([
                'message' => 'User could not be updated.'
            ], 422);
        }

        if (empty($changed)) {
            return response()->success(['message' => 'Nothing changed.'], 200);
        }

        return response()->success(['user_changes' => $changed], 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\User $user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(User $user)
    {
        // Check permissions
        if (!AuthUser::user()->can('CAN_SOFT_DELETE_USERS')) {
            return response()->invalidPermissions();
        }

        try {
	        if ($user->delete()) {
                $user->save();
		            $user->updateWordPressPermissions( 'all', null );
                try {
                    $user->refreshToken()->delete();
                } catch (\Exception $e) {
                    \Log::info('User does not have a refresh token stored in the database.', ['user_id' => $user->id]);                
                }
            } else {
		        \Log::error('Unable to delete user. UserID: ' . $user->id);
                return response()->failure(['message' => 'Unable to delete user.'], 422);
            }
            return response()->success(['message' => 'User deleted.'], 200);
        } catch (\Exception $e) {
            return response()->failure(['message' => 'User not found'], 404);
        }
    }

    /**
     * Reactivate a user.
     *
     * @param \App\User $user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reactivated(User $user)
    {
        // Check permissions
        if (!AuthUser::user()->can('CAN_CREATE_USER')) {
            return response()->invalidPermissions();
        }

        try {
            if (! $user->restore() ) {
            	\Log::error('Unable to restore user. UserID: ' . $user->id);
                return response()->failure(['message' => 'Unable to reactivate user.'], 422);
            }
            return response()->success(['message' => 'User reactivated.'], 200);
        } catch (\Exception $e) {
            return response()->failure(['message' => 'User not found'],404);
        }
    }

    /**
     * Update password for logged in users
     * @param Request $request
     * @param User    $user
     *
     * @return User|\Illuminate\Http\JsonResponse
     */
    public function updatePassword(Request $request, User $user)
    {
        // Check permissions
        if (!AuthUser::user()->can('CAN_UPDATE_MY_PROFILE')) {
            return response()->invalidPermissions();
        }

        $messages = [
            'newPassword.regex' => 'The password must contain at least one lowercase letter, one uppercase letter, and one number.',
        ];

        $validator = \Validator::make($request->only([
            'currentPassword',
            'newPassword'
        ]), [
            'currentPassword' => 'string|required',
            'newPassword'     => [
                'string',
                'required',
                'min:12,',
                'regex:/[a-z]/', // must contain at least one lowercase letter
                'regex:/[A-Z]/', // must contain at least one uppercase letter
                'regex:/[0-9]/', // must contain at least one number
                new PasswordRestrictionsCommon,
                new PasswordRestrictionsUser($user),
                new PasswordRestrictionsPrevious($user, $request->get('newPassword'))
            ]
        ], $messages);


        if ($validator->fails()) {
            return response()->failure([
                'message' => 'Validation Failure',
                'messages' => $validator->messages()
            ], 422);
        }

        $currentPassword = $request->get('currentPassword');
        $hashedPassword = $user->password;
        $newPassword = $request->get('newPassword');

        // Password Integrity Checks
        if (\Hash::check($currentPassword, $hashedPassword)) {

            $user->password = \Hash::make($newPassword);

            $user->save();

            $this->savePasswordPrevious($user);

        } else {
            return response()->failure(['message' => 'Current password does not match.'],422);
        }

        return response()->success(['message' => 'User password updated.'], 200);
    }
}
