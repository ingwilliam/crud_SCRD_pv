<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

$app = new Micro($di);

//Metodo que permite consultar toda la convocatoria con el fin de publicarla
$app->post('/search/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Si existe consulto la convocatoria
        $convocatoria = Convocatorias::findFirst($id);
        
        $conditions = ['convocatoria_padre_categoria'=>$id,'active'=>true];
        $categorias = Convocatorias::find(([
                                                'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                                                'bind' => $conditions,
                                            ]));
        //Si tiene diferentes_categorias esta en true debo hacer el filtro por cada categoria
        //De lo contrario el cronograma, administrativos, tecnicos y rondas es el mismo para todas sus categorias
        if($convocatoria->diferentes_categorias)
        {
             foreach($categorias as $categoria){                 
                 $conditions = ['convocatoria'=>$categoria->id,'active'=>true];
                 $cronogramas[$categoria->id] = Convocatoriascronogramas::find(([
                                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                                        'bind' => $conditions,
                                                    ]));            
                 
                 $documentos_administrativos[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Administrativos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = ".$categoria->id);
                 
                 $documentos_tecnicos[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Tecnicos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = ".$categoria->id);
                 
                 $rondas_evaluacion[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasrondas.*  FROM Convocatoriasrondas WHERE Convocatoriasrondas.active=true AND Convocatoriasrondas.convocatoria = ".$categoria->id);
                 
                 
                 
             }
        }
        else 
        {
            $conditions = ['convocatoria'=>$id,'active'=>true];
            $cronogramas[$id] = Convocatoriascronogramas::find(([
                                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                                    'bind' => $conditions,
                                                ]));
            
            $documentos_administrativos[$id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Administrativos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = ".$id);
                 
            $documentos_tecnicos[$id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Tecnicos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = ".$id);            
            
            $rondas_evaluacion[$id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasrondas.*  FROM Convocatoriasrondas WHERE Convocatoriasrondas.active=true AND Convocatoriasrondas.convocatoria = ".$id);
            
        }
        
        //consulto los tipos anexos listados
        $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='listados'");                        
        $tipo_documento_listados = str_replace(",", "','", "'".$tabla_maestra->valor."'");        
        $conditions = ['convocatoria'=>$id,'active'=>true];
        $listados = Convocatoriasanexos::find(([
                                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN ('.$tipo_documento_listados.')',
                                                'bind' => $conditions,
                                            ]));
        
        //consulto los tipos anexos documentacion
        $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='documentacion'");                        
        $tipo_documento_documentacion = str_replace(",", "','", "'".$tabla_maestra->valor."'");        
        $conditions = ['convocatoria'=>$id,'active'=>true];
        $documentacion= Convocatoriasanexos::find(([
                                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN ('.$tipo_documento_documentacion.')',
                                                'bind' => $conditions,
                                            ]));
        
        //consulto los tipos anexos avisos
        $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='avisos'");                        
        $tipo_documento_avisos = str_replace(",", "','", "'".$tabla_maestra->valor."'");        
        $conditions = ['convocatoria'=>$id,'active'=>true];
        $avisos= Convocatoriasanexos::find(([
                                                'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN ('.$tipo_documento_avisos.')',
                                                'bind' => $conditions,
                                            ]));
        
        
        //Creo todos los array del registro
        $array["convocatoria"] = $convocatoria;
        $array["categorias"] = $categorias;
        $array["cronogramas"] = $cronogramas;
        $array["documentos_administrativos"] = $documentos_administrativos;
        $array["documentos_tecnicos"] = $documentos_tecnicos;
        $array["rondas_evaluacion"] = $rondas_evaluacion;
        $array["listados"] = $listados;
        $array["documentacion"] = $documentacion;
        $array["avisos"] = $avisos;

        //Retorno el array
        echo json_encode($array);
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
});

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>