class MySQLDB
{
   private $conn;          // The MySQL database connection

   /* Class constructor */
   function MySQLDB(){
      /* Make connection to database */
      $this->conn = mysql_connect('www.olam-systems.com.mx', 'olamsyst_admin', 'Vai7eto,kaej') or die(mysql_error());
      mysql_select_db('olamsyst_presentation', $this->conn) or die(mysql_error());
   }

   /* Transactions functions */

   function begin(){
      $null = mysql_query("START TRANSACTION", $this->conn);
      return mysql_query("BEGIN", $this->conn);
   }

   function commit(){
      return mysql_query("COMMIT", $this->conn);
   }
  
   function rollback(){
      return mysql_query("ROLLBACK", $this->conn);
   }

   function transaction($q_array){
         $retval = 1;

      $this->begin();

         foreach($q_array as $qa){
            $result = mysql_query($qa['query'], $this->conn);
            if(mysql_affected_rows() == 0){ $retval = 0; }
         }

      if($retval == 0){
         $this->rollback();
         return false;
      }else{
         $this->commit();
         return true;
      }
   }

};