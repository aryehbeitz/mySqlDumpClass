<?php

// $backup = new backupDb('db_name', '/tmp/whatever.sql', true, false, '^([0-9]+){8}$', true);
class backupDb {
	function __construct($dbName, $outFile, $drop = false, $truncate = false, $regex = false, $invRegex = false) {
		$this->dbName = $dbName;
		$this->outFile = $outFile;
		$this->dbConnect("localhost", "user", "pass", $dbName);
		if ($this->link === false) die("no db connection");
		$this->drop = $drop;
		$this->truncate = $truncate;
		$this->regex = $regex;
		$this->invRegex = $invRegex;

		$this->createLoadTablesQuery();
		$this->loadTablelist();
		$this->runDbBackup();
	}
	function __destruct() {
		mysqli_close($this->link);
		echo "\n";
	}
	function dbConnect($host, $user, $pass, $dbName = false) {
		$this->link = mysqli_connect($host, $user, $pass);
		if (mysqli_connect_errno()) {
			$this->link = false;
			return;
		}
		if ($dbName !== false) {
		    mysqli_select_db($this->link, $dbName);
		}
	}
	private function createLoadTablesQuery() {
		$query = "SHOW TABLES FROM `{$this->dbName}`";
		if ($this->regex !== false) {
			$query .= " WHERE Tables_in_" . $this->dbName;
			if ($this->invRegex !== false) {
				$query .= " NOT";
			}
			//^[A-Z]+$
		 	$query .= " REGEXP '{$this->regex}'";
		}
		$this->query = $query;
	}
	private function loadTablelist() {
		$this->tableNames = [];
		if ($result = mysqli_query($this->link, $this->query)) {
			while ($row = mysqli_fetch_assoc($result)) {
				$this->tableNames[] = $row["Tables_in_{$this->dbName}"];
			}
			mysqli_free_result($result);
		}
	}
	private function runDbBackup() {
		$len = count($this->tableNames);
		for ($i=0; $i<$len; $i++) {
			echo "\nbacking up " . $this->tableNames[$i];
			$this->backupTable($this->tableNames[$i]);
			$this->saveOutput();
			echo "...done $i/$len";
		}
	}
	private function saveOutput() {
		file_put_contents($this->outFile, $this->output . PHP_EOL, FILE_APPEND);
	}
	private function backupTable($tableName) {
		$table = $tableName;
		
		$query = "describe `$this->dbName`.`$table`";
		$theTypes = [];
		$theTypesNull = [];
		if ($result = mysqli_query($this->link, $query)) {
			while ($row = mysqli_fetch_assoc($result)) {
				$itsType = $row["Type"];
				if ($row["Null"] == 'YES') {
					$theTypesNull[] = 1;
				} else {
					$theTypesNull[] = 0;
				}
				if (
					stristr($itsType, "int") !== FALSE ||
					stristr($itsType, "float") !== FALSE ||
					stristr($itsType, "double") !== FALSE ||
					stristr($itsType, "real") !== FALSE) {
						$theTypes[] = 1;
				} else {
					$theTypes[] = 0;
				}
			}
			mysqli_free_result($result);
		}

		
		$query = "SHOW CREATE TABLE `$this->dbName`.`$table`";
		$return = "USE $this->dbName;\n\n";
		if ($this->drop !== false) {
			$return.= "DROP TABLE IF EXISTS `$this->dbName`.`$table`;";
		}
		$row2 = mysqli_fetch_row(mysqli_query($this->link, $query));
		$return.= "\n\n".$row2[1].";\n\n";

		if ($this->truncate !== false) {
			$return.= "\n\nTRUNCATE `$this->dbName`.`$table`;" . "\n\n";
		}
		$first=1;
		$return .= "INSERT INTO `$this->dbName`.`$table` VALUES ";

		$query = "SELECT * FROM `$this->dbName`.`$table`";
		$num_fields = 0;
		if ($result = mysqli_query($this->link, $query)) {
			$num_fields = mysqli_num_fields($result);
			mysqli_free_result($result);
		}
		$query = "SELECT * FROM `$this->dbName`.`$table`";
		if ($result = mysqli_query($this->link, $query)) {
			while($row = @mysqli_fetch_row($result)) {
				if (!$first) $return.=", ";
				$first=0;
				$return.= '(';
				for($j=0; $j < $num_fields; $j++) {
					$row[$j] = addslashes($row[$j]);
					if (isset($row[$j])) {
						if ($row[$j] == null && $theTypesNull[$j] == 1) {
							$return.= 'NULL';
						}
						if ($row[$j] != null && $theTypesNull[$j] == 1 && $row[$j] == '') {
							$return.= "''";
						}
						if ($row[$j] != null) {
							if ($theTypes[$j] == 0) {
								$return.= "'".$row[$j]."'" ;
							} else if ($theTypes[$j] == 1) {
								$return.= $row[$j] ;
							}
						}
					}
					if ($j < ($num_fields-1)) { $return.= ','; }
				}
				$return.= ")\n";
			}
			mysqli_free_result($result);
		}
		$return.=";\n\n\n";
		$this->output = $return;
	}
}
