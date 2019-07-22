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

// Recupera todos las areas seleccionados de un usuario determinado
$app->get('/select_user/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $phql = 'SELECT p.id,p.nombre,up.id AS checked FROM Convocatorias AS p LEFT JOIN Usuariosareas AS up ON p.id = up.area AND up.usuario=' . $id . ' WHERE p.active = true ORDER BY p.nombre';

                $areas_usuario = $app->modelsManager->executeQuery($phql);

                echo json_encode($areas_usuario);
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

/* Verificar si un usuario puede cambiar el estado a una convocatoria
 * @param estado 1 Creada, 2 Visto bueno, 3 Verificada, 4 Aprobada, 5 Publicada
 */
$app->get('/verificar_estado', function () use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            /* Perfiles de los usuarios
             * 11 Crear convocatorias, 12 Visto bueno a las convocatorias, 13 Verificar convocatorias, 14 Aprobar convocatorias, 15 Publicar convocatorias
             */
            $usuariosperfiles = array();
            switch ($request->get('estado')) {
                case 1:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 11");
                    break;
                case 2:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 12");
                    break;
                case 3:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 13");
                    break;
                case 4:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 14");
                    break;
                case 5:
                    $usuariosperfiles = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 15");
                    break;
            }
            echo json_encode($usuariosperfiles->id);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
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
                0 => 'c.anio',
                1 => 'e.nombre',
                2 => 'a.nombre',
                3 => 'l.nombre',
                4 => 'en.nombre',
                5 => 'c.nombre',
                6 => 'c.descripcion',
                7 => 'p.nombre',
                8 => 'es.nombre',
                9 => 'c.orden',
            );



            if(!empty($request->get('convocatoria')))
            {
                $where .= " WHERE c.active IN (true,false)";
                $where .= " AND c.convocatoria_padre_categoria=".$request->get('convocatoria');
            }
            else
            {
                $where .= " INNER JOIN Entidades AS e ON e.id=c.entidad";
                $where .= " INNER JOIN Programas AS p ON p.id=c.programa";
                $where .= " LEFT JOIN Areas AS a ON a.id=c.area";
                $where .= " LEFT JOIN Lineasestrategicas AS l ON l.id=c.linea_estrategica";
                $where .= " LEFT JOIN Enfoques AS en ON en.id=c.enfoque";
                $where .= " INNER JOIN Estados AS es ON es.id=c.estado";
                $where .= " WHERE c.active IN (true,false) AND c.convocatoria_padre_categoria IS NULL";
            }

            //Condiciones para la consulta del input filter de la tabla categorias
            if (!empty($request->get("search")['value'])) {
                if(!empty($request->get('convocatoria')))
                {
                    $where .= " AND ( UPPER(" . $columns[5] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[6] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }
            }

            //Condiciones para la consulta del select del buscador principal
            if (!empty($request->get("params"))) {
                foreach (json_decode($request->get("params")) AS $clave=>$valor)
                {
                    if($clave=="nombre" && $valor!="")
                    {
                        $where .= " AND ( UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[5] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[7] . ") LIKE '%" . strtoupper($valor) . "%' ";
                        $where .= " OR UPPER(" . $columns[8] . ") LIKE '%" . strtoupper($valor) . "%' )";
                    }

                    if($valor!="" && $clave!="nombre")
                    {
                        $where=$where." AND c.".$clave." = ".$valor;
                    }
                }
            }


            //Defino el sql del total y el array de datos
            $sqlTot = "SELECT count(*) as total FROM Convocatorias AS c";
            $sqlTotEstado = "SELECT c.estado,count(c.id) as total FROM Convocatorias AS c";

            if(!empty($request->get('convocatoria')))
            {
                $sqlRec = "SELECT ". $columns[5] . "," . $columns[6] . "," . $columns[9] . " ,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro , concat('<button title=\"',c.id,'\" type=\"button\" class=\"btn btn-warning btn_categoria\" data-toggle=\"modal\" data-target=\"#editar_convocatoria\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatorias AS c";
            }
            else
            {
                $sqlRec = "SELECT " . $columns[0] . " ," . $columns[1] . " AS entidad," . $columns[2] . " AS area," . $columns[3] . " AS linea_estrategica," . $columns[4] . " AS enfoque," . $columns[5] . "," . $columns[6] . "," . $columns[7] . " AS programa ," . $columns[8] . " AS estado ," . $columns[9] . " ,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro , concat('<button type=\"button\" class=\"btn btn-danger\" onclick=\"form_edit_page(2,',c.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as ver_convocatoria,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro , concat('<span class=\"span_',$columns[8],'\">',$columns[8],'</span>') as estado_convocatoria,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro, concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_page(1,',c.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatorias AS c";
            }

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;
                $sqlTotEstado .= $where;
                $sqlRec .= $where;
            }

            if(!empty($request->get('convocatoria')))
            {
                //Concateno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY " . $columns[9] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            }
            else
            {
                //Concateno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            }


            //Concateno el group by de estados
            $sqlTotEstado .= " GROUP BY 1";

            //ejecuto el total de registros actual
            $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

            //creo el array
            $json_data = array(
                "draw" => intval($request->get("draw")),
                "recordsTotal" => intval($totalRecords["total"]),
                "recordsFiltered" => intval($totalRecords["total"]),
                "dataEstados" => $app->modelsManager->executeQuery($sqlTotEstado),
                "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
            );
            //retorno el array en json
            echo json_encode($json_data);
        } else {
            //retorno el array en json null
            echo json_encode("error_token");
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
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                $convocatoria = new Convocatorias();
                $convocatoria->creado_por = $user_current["id"];
                $convocatoria->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoria->active = true;
                $convocatoria->estado = 1;
                if ($convocatoria->save($post) === false) {
                    echo "error";
                } else {                                        
                    //Se crea la carpeta principal de la convocatoria
                    if( $chemistry_alfresco->newFolder("/Sites/convocatorias", $convocatoria->id) == "ok" )
                    {
                        //Se crea las carpetas necesarias para los posibles archivos
                        $chemistry_alfresco->newFolder("/Sites/convocatorias/".$convocatoria->id, "documentacion");
                        $chemistry_alfresco->newFolder("/Sites/convocatorias/".$convocatoria->id, "listados");
                        $chemistry_alfresco->newFolder("/Sites/convocatorias/".$convocatoria->id, "avisos");
                        $chemistry_alfresco->newFolder("/Sites/convocatorias/".$convocatoria->id, "propuestas");                                                
                        echo $convocatoria->id;
                    }
                    else
                    {
                        echo "error_alfresco";
                    }                    
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

// Crear registro
$app->post('/new_categoria', function () use ($app, $config) {
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                $convocatoria = Convocatorias::findFirst(json_decode($post["convocatoria_padre_categoria"]));
                $convocatoria->id = null;
                $convocatoria->creado_por = $user_current["id"];
                $convocatoria->fecha_creacion = date("Y-m-d H:i:s");
                $convocatoria->active = true;
                $convocatoria->estado = null;
                $convocatoria->convocatoria_padre_categoria = $post["convocatoria_padre_categoria"];
                if ($convocatoria->save($post) === false) {
                    echo "error";
                } else {
                    echo $convocatoria->id;
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
}
);

// Editar registro
$app->put('/edit_categoria/{id:[0-9]+}', function ($id) use ($app, $config) {
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $convocatoria = Convocatorias::findFirst(json_decode($id));
                $convocatoria->actualizado_por = $user_current["id"];
                $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                if($put["numero_estimulos"]=="")
                {
                    unset($put["numero_estimulos"]);
                }
                if ($convocatoria->save($put) === false) {
                    echo "error";
                } else {
                    echo $id;
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
}
);

// Editar registro
$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
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
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $convocatoria = Convocatorias::findFirst(json_decode($id));
                $convocatoria->actualizado_por = $user_current["id"];
                $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                if($put["tiene_categorias"]=="false")
                {
                    $put["diferentes_categorias"]=FALSE;
                    $put["mismos_jurados_categorias"]=FALSE;
                }

                if($put["numero_estimulos"]=="")
                {
                    unset($put["numero_estimulos"]);
                }
                if($put["localidad"]=="")
                {
                    unset($put["localidad"]);
                }
                if($put["upz"]=="")
                {
                    unset($put["upz"]);
                }
                if($put["barrio"]=="")
                {
                    unset($put["barrio"]);
                }
                if ($convocatoria->save($put) === false) {
                    echo "error";
                } else {
                    echo $id;
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

// Eliminar registro
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
                // Consultar el usuario que se esta editando
                $user = Convocatorias::findFirst(json_decode($id));
                $user->active = false;
                if ($user->save($user) === false) {
                    echo "error";
                } else {
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

// Eliminar registro
$app->delete('/delete_categoria/{id:[0-9]+}', function ($id) use ($app, $config) {
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
                // Consultar el usuario que se esta editando
                $user = Convocatorias::findFirst(json_decode($id));
                $user->active = $request->getPut('active');
                if ($user->save($user) === false) {
                    echo "error";
                } else {
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

//Busca el registro
$app->get('/search', function () use ($app) {
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
                $convocatoria = Convocatorias::findFirst($request->get('id'));
            }
            else
            {
                $convocatoria = new Convocatorias();
            }
            //Creo todos los array de la convocatoria
            $array["convocatoria"]=$convocatoria;

            //Ejemplo de como llamar los objetos relacionados
            /*
            $array["barrio_w"]= Barrios::findFirst("id=1");
            $array["barrio_localidad_id_w"]= $array["barrio_w"]->localidad;
            $array["barrio_localidad_obj_w"]= $array["barrio_w"]->getLocalidades();
            $array["barrio_localidad_nombre_w"]= $array["barrio_w"]->getLocalidades()->nombre;
            $array["barrio_localidad_ciudad_id_w"]= $array["barrio_w"]->getLocalidades()->id;
            $array["barrio_localidad_ciudad_obj_w"]= $array["barrio_w"]->getLocalidades()->getCiudades();
            $array["barrio_localidad_ciudad_nombre_w"]= $array["barrio_w"]->getLocalidades()->getCiudades()->nombre;
            */

            $array["programas"]= Programas::find("active=true");
            $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");
            $array["coberturas"]= Coberturas::find("active=true");
            $array["localidades"]= Localidades::find("active=true");
            $array["upzs"]=array();
            $array["barrios"]=array();
            if(isset($convocatoria->id))
            {
                $array["modalidades"]= Modalidades::find("active=true AND programa=".$convocatoria->programa);
                $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id AND Convocatoriasparticipantes.convocatoria= ".$convocatoria->id." WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");
                $array["perfiles_jurados"]= Convocatoriasparticipantes::find(['convocatoria = '.$convocatoria->id.' AND tipo_participante=4','order' => 'orden']);
                if(isset($convocatoria->localidad))
                {
                    $array["upzs"]= Upzs::find("active=true AND localidad=".$convocatoria->localidad);
                    $array["barrios"]= Barrios::find("active=true AND localidad=".$convocatoria->localidad);                
                }                
                $array["categorias"]= Convocatorias::find(['convocatoria_padre_categoria = '.$convocatoria->id.' AND active=TRUE','order' => 'nombre']);                
            }             
            $array["enfoques"]= Enfoques::find("active=true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active=true");
            $array["areas"]= Areas::find("active=true");
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='cantidad_perfil_jurado'");
            $array["cantidad_perfil_jurados"] = explode(",", $tabla_maestra[0]->valor);
            $array["tipos_convenios"]= Tiposconvenios::find("active=true");
            $array["tipos_estimulos"]= Tiposestimulos::find("active=true");
            $array["entidades"]= Entidades::find("active=true");
            $array["areas_conocimientos"]= Areasconocimientos::find("active=true AND id<>9");
            $array["niveles_educativos"]= Niveleseducativos::find("active=true");
            $array["estados"]= Estados::find("active=true AND tipo_estado='convocatorias' AND id<>5 ORDER BY orden");
            $array["distribuciones_bolsas"]= $convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => 'Bolsa'
                                                                                        ],
                                                                                        'order'      => 'orden ASC',
                                                                                    ]);
            $array_distribuciones_especies= $convocatoria->getConvocatoriasrecursos([
                                                                                        'tipo_recurso = :tipo_recurso:',
                                                                                        'bind' => [
                                                                                            'tipo_recurso' => 'Especie'
                                                                                        ],
                                                                                        'order'      => 'orden ASC',
                                                                                    ]);
            $array["distribuciones_especies"] = array();

            foreach ($array_distribuciones_especies as $especie) {
                $array_interno=array();
                $array_interno["id"]=$especie->id;
                $array_interno["orden"]=$especie->orden;
                $array_interno["recurso_no_pecuniario"]=$especie->recurso_no_pecuniario;
                $array_interno["nombre_recurso_no_pecuniario"]=$especie->getRecursosnopecuniarios()->nombre;
                $array_interno["valor_recurso"]=$especie->valor_recurso;
                $array_interno["descripcion_recurso"]=$especie->descripcion_recurso;
                $array["distribuciones_especies"][]=$array_interno;
            }


            $array["recursos_no_pecunarios"]= Recursosnopecuniarios::find("active=true");
            for($i = date("Y"); $i >= 2016; $i--){
                $array["anios"][] = $i;
            }
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

//Busca el registro
$app->get('/load_search', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $array=array();
            for($i = date("Y"); $i >= 2016; $i--){
                $array["anios"][] = $i;
            }
            $array["entidades"]= Entidades::find("active = true");
            $array["areas"]= Areas::find("active = true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active = true");
            $array["programas"]= Programas::find("active = true");
            $array["enfoques"]=Enfoques::find("active = true");
            $array["estados_convocatorias"] = Estados::find(
                                                            array(
                                                                "tipo_estado = 'convocatorias' AND active = true AND id NOT IN (6)",
                                                                "order" => "orden"
                                                            )
                                                            );
            echo json_encode($array);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo";
    }
}
);



/*Cesar britto
Retorna información de id y nombre las categorias asociadas a la convocatoria */
$app->get('/select_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $categorias=  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if($request->get('id'))
            {
                //Valida que la convocatoria tenga categorias
                if( Convocatorias::count( "id=".$request->get('id')." AND tiene_categorias = true" ) > 0  ){
                  
                  $convocatorias = Convocatorias::find(
                      [
                          "convocatoria_padre_categoria = ".$request->get('id'),
                          'order' => 'nombre',
                      ]
                    );

                    //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
                  foreach ( $convocatorias as $key => $value) {
                          $categorias[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                    }

                }



            }

            echo json_encode($categorias);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo".$ex->getMessage();
    }
}
);


/*Retorna información de id y nombre las categorias asociadas a la convocatoria */
$app->get('/rondas', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $rondas=  array();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual != false ) {

            //Si existe consulto la convocatoria
            if($request->get('idcat'))
            {
              $rondas = Convocatoriasrondas::find(
                  [
                      "convocatoria= ".$request->get('idcat'),
                      'order' => 'numero_ronda',
                  ]
                );

                //Se construye un array con la información de id y nombre de cada convocatoria para establece rel componente select
              /*foreach ( $convocatorias as $key => $value) {
                      $rondas[$key]= array("id"=>$value->id, "nombre"=>$value->nombre);
                }*/

            }

            echo json_encode($rondas);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
        echo "error_metodo".$ex->getMessage();
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
