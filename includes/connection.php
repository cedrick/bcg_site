<?php 
Class Connection {

		var $connection;

		function connectdb($database) {
			$this->connection = mssql_connect("HELIX","sa","1nfin1ty");
			                            if($this->connection)
										   {
											   if(mssql_select_db($database))
											      {
													return TRUE;
												  }
												  
												else{
													  return FALSE;
												    }
										   }
										   
										 else{ echo "Error Connection";}
										 
				 						
										
		                               }


		function closedb() {
								mssql_close($this->connection) or die("Unable to close database: ");
								$this->connection = false;
								return;
							}
							
		







}

?>