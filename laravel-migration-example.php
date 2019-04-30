<?php

// Example of Laravel Migration

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;
if (!Capsule::schema()->hasTable('fs_products_metas')) {
    Capsule::schema()->create('fs_products_metas', function (Blueprint $table) {
        $table->increments('metaId');
        $table->string('metaKey', 30);
        $table->mediumText('metaValue')->nullable();
        $table->integer('productId')->unsigned();
        $table->index(['productId', 'metaKey']);
        $table->foreign('productId')->references('id')->on('fs_products')->onDelete('cascade');
    });
}
Capsule::schema()->table('fs_products', function (Blueprint $table) {
    $table->foreign('vendorId')->references('id')->on('fs_products_vendor')->onDelete('cascade');
});
Capsule::schema()->table('fs_products_category_relationships', function (Blueprint $table) {
    $table->foreign('categoryId')->references('id')->on('fs_products_categories')->onDelete('cascade');
    $table->foreign('productId')->references('id')->on('fs_products')->onDelete('cascade');
});
Capsule::unprepared('CREATE TRIGGER fs_ban_product_on_delete BEFORE DELETE ON `fs_products` FOR EACH ROW
BEGIN
   INSERT INTO `fs_products_banned` (`uuid`, `vendorId`) VALUES (OLD.uuid, OLD.vendorId);
END');
CAPSULE::unprepared('CREATE TRIGGER fs_dont_insert_banned_products BEFORE INSERT ON `fs_products` FOR EACH ROW
BEGIN
	IF (SELECT COUNT(*) FROM fs_products_banned WHERE vendorId=NEW.vendorId AND uuid=NEW.uuid) 	THEN
		SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Cannot add product that has been banned.";
	END IF;
END');
