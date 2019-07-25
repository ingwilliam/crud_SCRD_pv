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
        //Si existe consulto la convocatoria y creo el objeto
        $convocatoria = Convocatorias::findFirst($id);
        $array_convocatoria["programa"] = $convocatoria->getProgramas()->nombre;
        $array_convocatoria["convocatoria"] = $convocatoria->nombre;
        $array_convocatoria["entidad"] = $convocatoria->getEntidades()->nombre;
        $array_convocatoria["descripcion"] = $convocatoria->descripcion;
        $array_convocatoria["estado"] = "Estado : " . $convocatoria->getEstados()->nombre;
        $array_convocatoria["linea"] = $convocatoria->getLineasestrategicas()->nombre;
        $array_convocatoria["area"] = $convocatoria->getAreas()->nombre;
        $array_convocatoria["tiene_categorias"] = $convocatoria->tiene_categorias;        
        $array_convocatoria["diferentes_categorias"] = $convocatoria->diferentes_categorias;
        $array_convocatoria["numero_estimulos"] = $convocatoria->numero_estimulos;
        $array_convocatoria["valor_total_estimulos"] = "$ " . number_format($convocatoria->valor_total_estimulos, 0, '', '.');
        $array_convocatoria["bolsa_concursable"] = $convocatoria->bolsa_concursable;
        $array_convocatoria["descripcion_bolsa"] = $convocatoria->descripcion_bolsa;
        $array_convocatoria["objeto"] = $convocatoria->objeto;
        $array_convocatoria["no_pueden_participar"] = $convocatoria->no_pueden_participar;
        $array_convocatoria["derechos_ganadores"] = $convocatoria->derechos_ganadores;
        $array_convocatoria["deberes_ganadores"] = $convocatoria->deberes_ganadores;

        $tipo_convocatoria="";
        
        //Valido que la convocatorias no tenga categorias            
        if ($convocatoria->tiene_categorias == false) {
            $tipo_convocatoria="general";            
            //Verifico si el bolsa y su dritribucion
            if ($convocatoria->bolsa_concursable) {
                //Si es Dinero
                if ($convocatoria->tipo_estimulo == 1) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                'tipo_recurso = :tipo_recurso:',
                                'bind' => [
                                    'tipo_recurso' => 'Bolsa'
                                ],
                                'order' => 'orden ASC',
                    ]));
                }

                //Si es especie
                if ($convocatoria->tipo_estimulo == 2) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos([
                                'tipo_recurso = :tipo_recurso:',
                                'bind' => [
                                    'tipo_recurso' => 'Especie'
                                ],
                                'order' => 'orden ASC',
                    ]));
                }

                //Si es mixta
                if ($convocatoria->tipo_estimulo == 3) {
                    $array_convocatoria["numero_estimulos"] = count($convocatoria->getConvocatoriasrecursos());
                }
            }
            
        }
        else
        {
            //Array para consultar las convocatorias generales
            $conditions = ['convocatoria_padre_categoria' => $id, 'active' => true];
            $categorias = Convocatorias::find([
                        'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                        'bind' => $conditions,
                        "order" => 'orden',
            ]);
                
            //Valido que la convocatorias tenga categorias generales
            if($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == false){                                 

                $tipo_convocatoria="general";
                
                //Se crea todo el array de las rondas de evaluacion
                foreach ($categorias as $categoria) {
                    $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                    $consulta_rondas_evaluacion = Convocatoriasrondas::find(([
                                'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                'bind' => $conditions,
                    ]));

                    foreach ($consulta_rondas_evaluacion as $ronda) {
                        $rondas_evaluacion[$ronda->id]["ronda"] = $ronda->numero_ronda;
                        $rondas_evaluacion[$ronda->id]["nombre"] = "<b>Categoría:</b> ".$categoria->nombre." <br/><b>Ronda:</b> ".$ronda->nombre_ronda;
                        $rondas_evaluacion[$ronda->id]["descripcion"] = $ronda->descripcion_ronda;
                        $rondas_evaluacion[$ronda->id]["criterios"] = Convocatoriasrondascriterios::find(
                                        [
                                            "convocatoria_ronda = " . $ronda->id . "",
                                            "order" => 'orden'
                                        ]
                        );
                    }    
                }
            }
            else
            {
                //Valido que la convocatorias tenga categorias especiales            
                if($convocatoria->tiene_categorias == true && $convocatoria->diferentes_categorias == true){
                    $tipo_convocatoria="especifica";
                    if ($convocatoria->diferentes_categorias) {
                        foreach ($categorias as $categoria) {
                            $conditions = ['convocatoria' => $categoria->id, 'active' => true];
                            $cronogramas[$categoria->id] = Convocatoriascronogramas::find(([
                                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            $documentos_administrativos[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Administrativos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = " . $categoria->id);

                            $documentos_tecnicos[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasdocumentos.*  FROM Convocatoriasdocumentos INNER JOIN Requisitos ON Requisitos.id = Convocatoriasdocumentos.requisito AND Requisitos.tipo_requisito='Tecnicos' WHERE Convocatoriasdocumentos.active=true AND Convocatoriasdocumentos.convocatoria = " . $categoria->id);

                            $rondas_evaluacion[$categoria->id] = $app->modelsManager->executeQuery("SELECT  Convocatoriasrondas.*  FROM Convocatoriasrondas WHERE Convocatoriasrondas.active=true AND Convocatoriasrondas.convocatoria = " . $categoria->id);
                        }
                    }
                    
                }
            }
        }
        
        
        if($tipo_convocatoria=="general")
        {
            //Se crea todo el array del cronograma de actividades de la convocatoria simple            
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_cronogramas = Convocatoriascronogramas::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                        'bind' => $conditions,
            ]));
            foreach ($consulta_cronogramas as $evento) {
                $cronogramas[$evento->id]["tipo_evento"] = $evento->getTiposeventos()->nombre;
                if ($evento->getTiposeventos()->periodo) {
                    $cronogramas[$evento->id]["fecha"] = "desde " . date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a') . " hasta " . date_format(new DateTime($evento->fecha_fin), 'd/m/Y h:i:s a');
                } else {
                    $cronogramas[$evento->id]["fecha"] = date_format(new DateTime($evento->fecha_inicio), 'd/m/Y h:i:s a');
                }
                $cronogramas[$evento->id]["descripcion"] = $evento->descripcion;
                $cronogramas[$evento->id]["convocatoria"] = $id;
            }

            //Se crea todo el array de participantes
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_participantes = Convocatoriasparticipantes::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                        'bind' => $conditions,
            ]));
            foreach ($consulta_participantes as $participante) {
                $participantes[$participante->id]["participante"] = $participante->getTiposParticipantes()->nombre;
                $participantes[$participante->id]["descripcion"] = $participante->descripcion_perfil;
                $participantes[$participante->id]["convocatoria"] = $id;
            }

            //Se crea todo el array de documentos administrativos y tecnicos
            $conditions = ['convocatoria' => $id, 'active' => true];
            $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));
            foreach ($consulta_documentos_administrativos as $documento) {
                if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                    $documentos_administrativos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                    $documentos_administrativos[$documento->id]["descripcion"] = $documento->descripcion;
                    $documentos_administrativos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                    $documentos_administrativos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                    $documentos_administrativos[$documento->id]["orden"] = $documento->orden;
                    $documentos_administrativos[$documento->id]["convocatoria"] = $id;
                }

                if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                    $documentos_tecnicos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                    $documentos_tecnicos[$documento->id]["descripcion"] = $documento->descripcion;
                    $documentos_tecnicos[$documento->id]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                    $documentos_tecnicos[$documento->id]["tamano_permitido"] = $documento->tamano_permitido;
                    $documentos_tecnicos[$documento->id]["orden"] = $documento->orden;
                    $documentos_tecnicos[$documento->id]["convocatoria"] = $id;
                }
            }

            //consulto los tipos anexos listados
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='listados'");
            $tipo_documento_listados = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
            $listados = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_listados . ')',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));

            //consulto los tipos anexos documentacion
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='documentacion'");
            $tipo_documento_documentacion = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
            $documentacion = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_documentacion . ')',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));

            //consulto los tipos anexos avisos
            $tabla_maestra = Tablasmaestras::findFirst("active=true AND nombre='avisos'");
            $tipo_documento_avisos = str_replace(",", "','", "'" . $tabla_maestra->valor . "'");
            $conditions = ['convocatoria' => $id, 'active' => true];
            $avisos = Convocatoriasanexos::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_documento IN (' . $tipo_documento_avisos . ')',
                        'bind' => $conditions,
                        'order' => 'orden ASC',
            ]));
        }                       
        
        //Creo todos los array del registro
        $array["convocatoria"] = $array_convocatoria;
        $array["categorias"] = $categorias;
        $array["cronogramas"] = $cronogramas;
        $array["participantes"] = $participantes;
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

$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        echo $chemistry_alfresco->download($request->getPost('cod'));
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>