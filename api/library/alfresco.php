<?php
//LINK DE EJEMPLOS https://hotexamples.com/examples/-/CMISService/getContentStream/php-cmisservice-getcontentstream-method-examples.html

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once ('class/ChemistryPV.php');

$chemistry_alfresco=new ChemistryPV("http://192.168.56.101:8080/alfresco/api/-default-/public/cmis/versions/1.0/atom", "admin", "ingwilliam10");

//Validar si existe la carpeta creada
$ojj= $chemistry_alfresco->searchFolder("/Sites/convocatorias/App");
echo "<pre>";
print_r($ojj);


//CREAR CARPETA
$ojj= $chemistry_alfresco->newFolder("/Sites/convocatorias", "CESARBRITTTO");
//echo "<pre>";
//print_r($ojj);


////CREAR ARCHIVO
//$return = $chemistry_alfresco->newFile("/Sites/convocatorias/BARBOSA", "MATEEEO.txt", "VAMOS COLOMBIA", "text/plain");
//echo "<pre>";
//print_r($return);
//echo $return->id;


/* LISTAR TODOS LOS DOCUMENTOS DEL REPOSITORIO, LA IDEA ES HACER UN LINK ENVIANDO EL ID
 * Y EN LA RESPUETA DEVOLVER EL ARCHIVO
$myfolder = $chemistry_alfresco->getObjectByPath("/Sites/convocatorias/BARBOSA");
$objs = $chemistry_alfresco->getChildren($myfolder->id);
foreach ($objs->objectList as $obj)
{
    if ($obj->properties['cmis:baseTypeId'] == "cmis:document")
    {
        $chemistry_alfresco->download($obj->id);
        
    }
    elseif ($obj->properties['cmis:baseTypeId'] == "cmis:folder")
    {
        print "Folder: " . $obj->properties['cmis:name'] . "\n";
    } else
    {
        print "Unknown Object Type: " . $obj->properties['cmis:name'] . "\n";
    }
}
*/

////PERMITE BUSCAR POR CARPETA O POR NOMBRE DE ARCHIVO O POR PALABRA CLAVE
//$return = $chemistry_alfresco->search("/Sites/convocatorias/Barbosa","MATEEEO.txt");
//echo "<pre>";
//print_r($return);

?>