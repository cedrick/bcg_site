<?php
 
include ("connection.php");
Class Check extends Connection
{
    
	
	
	
	//function escape string
	function escape_string($string)
	{
	  
	 	return str_replace("'","''",$string);
	}
	 
	
	
	 //function for search
	 function main($search)
	 { 
		
	    if($this->connectdb("NSI_BCG"))
		{
	       
			//$permission=$_COOKIE['allowsave'];
			$escape=$this->escape_string($search);
			//$explode=explode(',',$escape);
		
		
				if($escape != NULL)
				{
							
						$sql= "
							SELECT Business_Name,Category,Address,Website,Email,Email2,City,PhoneNumber,ListCode from  Calllist where I3_RowID = '$escape'
							";
							
							$result=mssql_query($sql);
						 	$count=mssql_num_rows($result);
						 	$counter="<b>Result</b>".":"." ".$count." "."Record Found!";
						 	echo '<div class="search_result">'.'<font color="#800000" face="Arial">'.'<center>'.$counter.'</center>'.'</font>'.'</div>';
							
							  	if($count>0)
									{   
								    $x=0; 
									echo "<table width=1100 border=0 align=center cellspacing=1 cellpadding=2 bgcolor=#8FBD29 style='font-size:12px'>";
									
										echo"<td>"."#"."</td>";
										echo"<td>"."Phone Number"."</td>";
										echo"<td>"."Business Name"."</td>";
										echo"<td>"."Category"."</td>";
										echo"<td>"."Address"."</td>";
										echo"<td>"."Website"."</td>";
										echo"<td>"."Email"."</td>";
										echo"<td>"."Email2"."</td>";
										echo"<td>"."City"."</td>";
										echo"<td>"."List Code"."</td>";
									
									while($row=mssql_fetch_array($result))
										{
											
											if($x%2==0)
											{
												$color = " bgcolor = '#E4F2E4' ";
											}else
											{
												$color=" bgcolor='#E5F3F7'";
										  	}
			
							        		$x++;
											echo '<tr'.$color.'>';
											echo"<td>".$x."</td>";
											echo"<td>". $row['PhoneNumber'] ."</td>";
											echo"<td>". $row['Business_Name'] ."</td>";
											echo"<td>". $row['Category']."</td>";
											echo"<td>". $row['Address'] ."</td>";
											echo"<td>". $row['Website'] ."</td>";
											echo"<td>".$row['Email'] ."</td>";
											echo"<td>". $row['Email2'] ."</td>";
											echo"<td>". $row['City'] ."</td>";
											echo"<td>".$row['ListCode'] ."</td>";
											echo"</tr>";
			
										}
										echo "</table>";
										
									}else
									{
										echo $_COOKIE['error_msg']='<div class="search_result">'.'<font color="#800000" face="Arial">'.'<center>'.'<b>'."Record is not Existing!".'</b>'.'</center>'.'</font>'.'</div>';;
									}
											
					}else 
					{
						echo $_COOKIE['error_msg']='<div class="search_result">'.'<font color="#800000" face="Arial">'.'<center>'.'<b>'."You Searched for Nothing!".'</b>'.'</center>'.'</font>'.'</div>';;
						
					}
					
				
		   $this->closedb();
				
		}
		else
			{
				echo'<div class="counter">'. "Database error".'</div>';
			}
	 }
	 
	 
}
?>
