<?php
    //error_reporting(E_ALL);
    //ini_set('display_errors', '1');
    require_once ('class/ChemistryPV.php');
    $i=1;
    foreach($_FILES as $clave => $valor){        
        $fileTmpPath = $valor['tmp_name'];        
        $fileSize = $valor['size'];
        $fileType = $valor['type'];
        $fileNameCmps = explode(".", $valor["name"]);
        $fileExtension = strtolower(end($fileNameCmps));                                
        $fileName = "file_".date("YmdHis").".".$fileExtension;
        $chemistry_alfresco=new ChemistryPV("http://192.168.56.101:8080/alfresco/api/-default-/public/cmis/versions/1.0/atom", "admin", "ingwilliam10");        
        $return = $chemistry_alfresco->newFile("/Sites/convocatorias/App/", $fileName, file_get_contents($fileTmpPath), trim($fileType));    
        //move_uploaded_file($fileTmpPath,"/archivos_jurados/".$fileName);
        print_r($return);
        exit;
        echo "<br/>";echo "<br/>";                        
    }
    exit;
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