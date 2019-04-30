-- This is a stored proc I created to pull options from all tables within a WordPress multi-site
-- Example usage: CALL mass_query("SELECT user_email FROM", "users", "WHERE user_email LIKE '%test'");

CREATE DEFINER=`DATABASE`@`%` PROCEDURE `mass_query`(IN preTable VARCHAR(255), IN tableName VARCHAR(30), IN postTable VARCHAR(255))
BEGIN

  DECLARE done INT DEFAULT FALSE;
  DECLARE tbl_name CHAR(25);
  DECLARE union_query TEXT;
  DECLARE has_initial_select INT DEFAULT FALSE;
  DECLARE wp_tables CURSOR FOR SELECT TABLE_NAME AS tbl FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME REGEXP CONCAT('^wp_([0-9]+)_',tableName,'$') OR TABLE_NAME = 'wp_options';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  SET @isSelect = preTable LIKE 'SELECT %';

  DROP TEMPORARY TABLE IF EXISTS temp_mass_container;
  
  OPEN wp_tables;

  read_loop: LOOP
    FETCH wp_tables INTO tbl_name;
    SET @tbl = tbl_name;
    IF done THEN
      LEAVE read_loop;
    END IF;
    SET @sql_query = CONCAT(preTable,' ',@tbl,' ',postTable);
    PREPARE sql_command FROM @sql_query;
    IF @isSelect THEN
		IF has_initial_select THEN
            SET @the_query = CONCAT('INSERT INTO temp_mass_container ',REPLACE(@sql_query,' FROM',CONCAT(', "',tbl_name,'" AS wp_table FROM')));
            PREPARE the_query FROM @the_query;
            EXECUTE the_query;
            DEALLOCATE PREPARE the_query;
        ELSE
			SET @the_query = CONCAT('CREATE TEMPORARY TABLE IF NOT EXISTS temp_mass_container AS ',REPLACE(@sql_query,' FROM',CONCAT(', "',tbl_name,'" AS wp_table FROM')));
            PREPARE the_query FROM @the_query;
            EXECUTE the_query;
            DEALLOCATE PREPARE the_query;
            SET has_initial_select = TRUE;
        END IF;
    ELSE
		EXECUTE sql_command;
    END IF;
    DEALLOCATE PREPARE sql_command;
  END LOOP;

  CLOSE wp_tables;
  
  IF @isSelect THEN
    SELECT * FROM temp_mass_container;
    DROP TEMPORARY TABLE IF EXISTS temp_mass_container;
  END IF;
END
