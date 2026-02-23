<?php

include(__DIR__ . '/connection.php');

Class Check extends Connection
{
    /**
     * Search for a record by I3_RowID using parameterized queries.
     */
    function main($search)
    {
        if ($this->connectdb(DB_NAME)) {

            $search = trim($search);

            if ($search != NULL) {

                $sql = "SELECT Business_Name, Category, Address, Website, Email, Email2, City, PhoneNumber, ListCode
                        FROM Calllist WHERE I3_RowID = :search";

                try {
                    $stmt = $this->getConnection()->prepare($sql);
                    $stmt->bindParam(':search', $search, PDO::PARAM_STR);
                    $stmt->execute();

                    $count = $stmt->rowCount();
                    $counter = "<b>Result</b>: " . (int)$count . " Record Found!";
                    echo '<div class="search_result"><font color="#800000" face="Arial"><center>' . $counter . '</center></font></div>';

                    if ($count > 0) {
                        $x = 0;
                        echo "<table width=1100 border=0 align=center cellspacing=1 cellpadding=2 bgcolor=#8FBD29 style='font-size:12px'>";

                        echo "<td>#</td>";
                        echo "<td>Phone Number</td>";
                        echo "<td>Business Name</td>";
                        echo "<td>Category</td>";
                        echo "<td>Address</td>";
                        echo "<td>Website</td>";
                        echo "<td>Email</td>";
                        echo "<td>Email2</td>";
                        echo "<td>City</td>";
                        echo "<td>List Code</td>";

                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

                            if ($x % 2 == 0) {
                                $color = " bgcolor = '#E4F2E4' ";
                            } else {
                                $color = " bgcolor='#E5F3F7'";
                            }

                            $x++;
                            echo '<tr' . $color . '>';
                            echo "<td>" . $x . "</td>";
                            echo "<td>" . htmlspecialchars($row['PhoneNumber'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Business_Name'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Category'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Address'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Website'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Email'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['Email2'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['City'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "<td>" . htmlspecialchars($row['ListCode'], ENT_QUOTES, 'UTF-8') . "</td>";
                            echo "</tr>";
                        }
                        echo "</table>";

                    } else {
                        echo '<div class="search_result"><font color="#800000" face="Arial"><center><b>Record is not Existing!</b></center></font></div>';
                    }

                } catch (PDOException $e) {
                    error_log("Query error: " . $e->getMessage());
                    echo '<div class="search_result"><font color="#800000" face="Arial"><center><b>A database error occurred.</b></center></font></div>';
                }

            } else {
                echo '<div class="search_result"><font color="#800000" face="Arial"><center><b>You Searched for Nothing!</b></center></font></div>';
            }

            $this->closedb();

        } else {
            echo '<div class="counter">Database error</div>';
        }
    }
}
?>
