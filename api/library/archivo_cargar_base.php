<?php
    //error_reporting(E_ALL);
    //ini_set('display_errors', '1');
    $explode = explode(',', substr($_POST["srcData"], 5), 2);        
    $data = $explode[1];    
    //file_put_contents("/var/www/html/Chemistry-Convocatorias/".$_POST["srcName"], base64_decode($data));
    
    require_once ('./ChemistryPV.php');

    $chemistry_alfresco=new ChemistryPV("http://192.168.1.90:8080/alfresco/api/-default-/public/cmis/versions/1.0/atom", "admin", "ingwilliam10");       
    $return = $chemistry_alfresco->newFile("/Sites/convocatorias/App/", $_POST["srcName"], base64_decode($data), $_POST["srcType"]);    
    echo $return->id;
?>