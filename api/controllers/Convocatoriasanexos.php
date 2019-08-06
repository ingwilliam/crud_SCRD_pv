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

// Recupera todos las modalidades dependiendo el programa
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {            
            $array = Convocatoriasanexos::find("active = true");            
            echo json_encode($array);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo". $ex->getMessage();
    }
}
);

// Recupera todos los registros
$app->get('/all', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Defino columnas para el orden desde la tabla html
            $columns = array(
                0 => 'ca.tipo_documento',
                1 => 'ca.nombre',
                2 => 'ca.descripcion',
                3 => 'ca.orden',
                4 => 'c.nombre',                
                5 => 'cpad.nombre',
            );

            //consulto los tipos anexos
            $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='".$request->get('anexos')."'");                        
            $tipo_documento = str_replace(",", "','", "'".$tabla_maestra->valor."'");
            
            //Array para consultar las posibles categorias de la convocatoria
            $conditions = ['convocatoria_padre_categoria' => $request->get("convocatoria"), 'active' => true];
            $categorias = Convocatorias::find([
                        'conditions' => 'convocatoria_padre_categoria=:convocatoria_padre_categoria: AND active=:active:',
                        'bind' => $conditions,
                        "order" => 'orden',
            ]);
            $array_categorias="";
            foreach ($categorias as $categoria) {
                $array_categorias= $array_categorias.$categoria->id.",";
            }            
            $array_categorias=$array_categorias.$request->get("convocatoria");
                        
            //Condiciones basicas
            $where .= " LEFT JOIN Convocatorias AS c ON c.id=ca.convocatoria";
            $where .= " LEFT JOIN Convocatorias AS cpad ON cpad.id=c.convocatoria_padre_categoria";            
            $where .= " WHERE ca.active IN (true,false) AND ca.convocatoria IN (".$array_categorias.") AND ca.tipo_documento IN (".$tipo_documento.")";
            
            //Condiciones para la consulta
            if (!empty($request->get("search")['value'])) {
                $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                $where .= " OR UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";                
                $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
            }   

            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatoriasanexos AS ca";
            $sqlRec = "SELECT " . $columns[0] . " ," . $columns[1] . "," . $columns[2] . "," . $columns[3] . ",c.nombre AS categoria, cpad.nombre AS convocatoria,concat('<input title=\"',ca.id,'\" type=\"checkbox\" class=\"check_activar_',ca.active,' activar_registro\" />') as activar_registro , concat('<button title=\"',ca.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button><button title=\"',ca.id_alfresco,'\" type=\"button\" class=\"btn btn-primary download_file\"><span class=\"glyphicon glyphicon-download-alt\"></span></button>') as acciones FROM Convocatoriasanexos AS ca";

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlRec .= $where;
            }

            //Concateno el orden y el limit para el paginador
            $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            
            //ejecuto el total de registros actual
            $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($totalRecords["total"]),
                "recordsFiltered" => intval($totalRecords["total"]),
                "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            //retorno el array en json null
            echo json_encode(null);
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo json_encode($ex->getMessage());
    }
}
);

// Crear registro
$app->post('/new', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);        
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));
        
        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $post["convocatoria"] == "" ){
                    $post["convocatoria"]=$post["convocatoria_padre_categoria"];                    
                }
                
                $convocatoriaanexo = new Convocatoriasanexos();                
                $convocatoriaanexo->creado_por = $user_current["id"];
                $convocatoriaanexo->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoriaanexo->active = true;
                if ($convocatoriaanexo->save($post) === false) {
                    echo "error";
                } else {
                    
                    //Recorro todos los posibles archivos
                    foreach($_FILES as $clave => $valor){        
                        $fileTmpPath = $valor['tmp_name'];                                
                        $fileType = $valor['type'];
                        $fileNameCmps = explode(".", $valor["name"]);
                        $fileExtension = strtolower(end($fileNameCmps));                                
                        $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$convocatoriaanexo->id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;                        
                        $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);                                                                            
                        if(strpos($return, "Error") !== FALSE){
                            echo "error_creo_alfresco";
                        }
                        else
                        {
                            $convocatoriaanexo->id_alfresco = $return;
                            if ($convocatoriaanexo->save($convocatoriaanexo) === false) {
                                echo "error";
                            } 
                            else {
                                echo $convocatoriaanexo->id;
                            }
                        }
                        
                    }                                                            
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo".$ex->getMessage();
    }
}
);

// Editar registro
$app->post('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);        

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                //Valido si el usuario selecciono una categoria, con el fin de asignarle la convocatoria principal
                if( $post["convocatoria"] == "" ){
                    $post["convocatoria"]=$post["convocatoria_padre_categoria"];                    
                }
                
                // Consultar el usuario que se esta editando
                $convocatoriaanexo = Convocatoriasanexos::findFirst(json_decode($id));                
                $convocatoriaanexo->actualizado_por = $user_current["id"];
                $convocatoriaanexo->fecha_actualizacion = date("Y-m-d H:i:s");
                
                //Recorro todos los posibles archivos
                foreach($_FILES as $clave => $valor){        
                    $fileTmpPath = $valor['tmp_name'];                                
                    $fileType = $valor['type'];
                    $fileNameCmps = explode(".", $valor["name"]);
                    $fileExtension = strtolower(end($fileNameCmps));                                
                    $fileName = "c".$request->getPost('convocatoria_padre_categoria')."d".$id."u".$convocatoriaanexo->creado_por."f".date("YmdHis").".".$fileExtension;                        
                    $return = $chemistry_alfresco->newFile("/Sites/convocatorias/".$request->getPost('convocatoria_padre_categoria')."/".$request->getPost('anexos')."/", $fileName, file_get_contents($fileTmpPath), $fileType);                                                                            
                    if(strpos($return, "Error") !== FALSE){
                        echo "error_creo_alfresco";
                    }
                    else
                    {
                        $convocatoriaanexo->id_alfresco = $return;
                        if ($convocatoriaanexo->save($post) === false) {
                            echo "error";
                        } else {
                            echo $id;
                        }
                    }
                }                                                                 
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);
        
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $convocatoriaanexo = Convocatoriasanexos::findFirst(json_decode($id));                
                if($convocatoriaanexo->active==true)
                {
                    $convocatoriaanexo->active=false;
                    $retorna="No";
                }
                else
                {
                    $convocatoriaanexo->active=true;
                    $retorna="Si";
                }
                
                if ($convocatoriaanexo->save($convocatoriaanexo) === false) {
                    echo "error";
                } else {
                    echo $retorna;
                }
            } else {
                echo "acceso_denegado";
            }           
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo".$ex->getMessage();
    }
});

//Busca el registro
$app->get('/search', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Si existe consulto la convocatoria
            if($request->get('id'))
            {    
                $convocatoriaanexo = Convocatoriasanexos::findFirst($request->get('id'));                                
            }
            else 
            {
                $convocatoriaanexo = new Convocatoriasanexos();
            }
            //Creo todos los array del registro
            $array["convocatoriaanexo"]=$convocatoriaanexo;
            
            //Creo los tipos de documentos para anexar
            $tabla_maestra= Tablasmaestras::findFirst("active=true AND nombre='".$request->get('anexos')."'");                        
            $array["tipo_documento"]=explode(",", $tabla_maestra->valor);            
            
            //Retorno el array
            echo json_encode($array);       
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);

//WILLIAM ARREGLAR DEBIDO A QUE NO ESTA DESCARGADO EL TIPO DE ARCHIVO
$app->post('/download_file', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);        
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {            
            echo $chemistry_alfresco->download($request->getPost('cod'));            
        } else {
            echo "error_token";
        }
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