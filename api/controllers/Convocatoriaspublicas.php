<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

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
        "host" => $config->database->host,"port" => $config->database->port,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

//Busca el registro
$app->get('/load_search', function () use ($app,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    //Consulto el token y consulto el usuario actual
    $token_actual = $tokens->verificar_token($request->get('token'));
    $user_current = json_decode($token_actual->user_current, true);
    
    try {
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            $array=array();
            for($i = date("Y")+1; $i >= 2016; $i--){
                $array["anios"][] = $i;
            }
            $array["entidades"]= Entidades::find("active = true");
            $array["areas"]= Areas::find("active = true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active = true");
            $array["programas"]= Programas::find("active = true");
            $array["enfoques"]=Enfoques::find("active = true");
            $array["estados_convocatorias"] = Estados::find(
                                                            array(
                                                                "tipo_estado = 'convocatorias' AND active = true AND id IN (1,2,3,4,5,6)",
                                                                "order" => "orden"
                                                            )
                                                            );
            echo json_encode($array);
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método load_search, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();        
            echo "error";
        }
    } catch (Exception $ex) {
        
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método load_search, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        $logger->close();        
        echo "error_metodo";
    }
}
);

// Recupera todos los registros
$app->get('/all', function () use ($app,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->get('token'));
    $user_current = json_decode($token_actual->user_current, true);
    $usuario_actual=$user_current["username"];
    try {
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto el usuario actual            
            $user_current = Usuarios::findFirst($user_current["id"]);            
            //Creo array de entidades que puede acceder el usuario
            $array_usuarios_entidades="";
            foreach ($user_current->getUsuariosentidades() as $usuario_entidad) {
                $array_usuarios_entidades = $array_usuarios_entidades . $usuario_entidad->entidad . ",";
            }
            $array_usuarios_entidades = substr($array_usuarios_entidades, 0, -1);
            
            //Creo array de areas que puede acceder el usuario
            $array_usuarios_areas="";
            foreach ($user_current->getUsuariosareas() as $usuario_area) {
                $array_usuarios_areas = $array_usuarios_areas . $usuario_area->area . ",";
            }
            $array_usuarios_areas = substr($array_usuarios_areas, 0, -1);
            
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
                $where .= " INNER JOIN Entidades AS e ON e.id=c.entidad AND e.id IN ($array_usuarios_entidades)";
                $where .= " INNER JOIN Programas AS p ON p.id=c.programa";
                $where .= " LEFT JOIN Areas AS a ON a.id=c.area";
                $where .= " LEFT JOIN Lineasestrategicas AS l ON l.id=c.linea_estrategica";
                $where .= " LEFT JOIN Enfoques AS en ON en.id=c.enfoque";
                $where .= " INNER JOIN Estados AS es ON es.id=c.estado";
                $where .= " WHERE c.active IN (true,false) AND c.convocatoria_padre_categoria IS NULL AND c.estado IN (5,6,32)";
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
                    if($clave=="anio" && $valor==null){
                        $valor=date("Y");
                    }
                    
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

            if(!empty($request->get('convocatoria')))
            {
                $sqlRec = "SELECT ". $columns[5] . "," . $columns[6] . "," . $columns[9] . " ,concat('<input title=\"',c.id,'\" type=\"checkbox\" class=\"check_activar_',c.active,' activar_categoria\" />') as activar_registro , concat('<button title=\"',c.id,'\" type=\"button\" class=\"btn btn-warning btn_categoria\" data-toggle=\"modal\" data-target=\"#editar_convocatoria\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatorias AS c";
            }
            else
            {                
                $sqlRec = "SELECT " . $columns[0] . " ," . $columns[1] . " AS entidad," . $columns[2] . " AS area," . $columns[3] . " AS linea_estrategica," . $columns[4] . " AS enfoque," . $columns[5] . "," . $columns[6] . "," . $columns[7] . " AS programa ," . $columns[8] . " AS estado ," . $columns[9] . " , concat('<button type=\"button\" class=\"btn btn-info\" onclick=\"form_edit_page(2,',c.id,')\"><span class=\"glyphicon glyphicon-eye-open\"></span></button>') as ver_convocatoria , concat('<span class=\"span_',$columns[8],'\">',$columns[8],'</span>') as estado_convocatoria,concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit_publica(1,',c.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Convocatorias AS c";
            }

            //concatenate search sql if value exist
            if (isset($where) && $where != '') {

                $sqlTot .= $where;                
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
                $sqlRec .= " ORDER BY c.estado ASC, c.entidad ASC , c.nombre ASC LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
            }

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
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método all, token caduco"', ['user' => $usuario_actual, 'token' => $request->get('token')]);
            $logger->close();
            echo json_encode("error_token");
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método all, ' . $ex->getMessage() . '"', ['user' => $usuario_actual, 'token' => $request->get('token')]);
        $logger->close();             
        echo json_encode($ex->getMessage());
    }
}
);

$app->put('/edit/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));
        
        //Consulto el usuario actual
        $user_current = json_decode($token_actual->user_current, true);
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $convocatoria = Convocatorias::findFirst(json_decode($id));
                $convocatoria->actualizado_por = $user_current["id"];
                $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                
                if($put["value_CKEDITOR"]!=""){
                    $put[$put["variable"]]=$put["value_CKEDITOR"];
                }                
                
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
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit, error al editar la convocatoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                
                    echo "error";
                } else {
                    
                    if($put["tiene_categorias"]=="true")
                    {                    
                        //Modifico el estado para todas las categorias
                        $phql = "UPDATE Convocatorias SET estado=:estado:, anio=:anio:, programa=:programa:, entidad=:entidad: ,area=:area: ,linea_estrategica=:linea_estrategica: ,enfoque=:enfoque:,modalidad=:modalidad: WHERE convocatoria_padre_categoria=:convocatoria_padre_categoria:";            
                        $app->modelsManager->executeQuery($phql, array(
                            'convocatoria_padre_categoria' => $id,
                            'estado' => $put["estado"],
                            'anio' => $put["anio"],
                            'programa' => $put["programa"],
                            'entidad' => $put["entidad"],
                            'area' => $put["area"],
                            'linea_estrategica' => $put["linea_estrategica"],
                            'modalidad' => $put["modalidad"],
                            'enfoque' => $put["enfoque"]
                        )); 
                        
                    }
                    
                    if($put["estado"]==5)
                    {
                        $phql = "UPDATE Convocatorias SET habilitar_cronograma=:habilitar_cronograma: WHERE convocatoria_padre_categoria=:convocatoria_padre_categoria: OR id=:convocatoria_padre_categoria:";            
                        $app->modelsManager->executeQuery($phql, array(
                            'convocatoria_padre_categoria' => $id,
                            'habilitar_cronograma' => FALSE                    
                        ));
                    }
                    
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriaspublicas en el método edit, edito con éxito la convocatoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                        
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();         
            
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close();                 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();         
        echo "error_metodo";
    }
}
);

// Eliminar registro
$app->delete('/delete_categoria/{id:[0-9]+}', function ($id) use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPut('token'));
    
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);
        
    try {        

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Convocatorias::findFirst(json_decode($id));
                $user->active = $request->getPut('active');
                if ($user->save($user) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método delete_categoria, error al editar la categoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Inactivo en el controlador Convocatoriaspublicas en el método delete_categoria, edito con éxito la categoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo "ok";
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método delete_categoria, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();         
            
                echo "acceso_denegado";
            }           
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método delete_categoria, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método delete_categoria, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();         
        echo "error_metodo";
    }
});


// Editar registro
$app->put('/edit_categoria/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPut('token'));
       
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);
    
    try {        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $put = $app->request->getPut();
                // Consultar el usuario que se esta editando
                $convocatoria = Convocatorias::findFirst(json_decode($id));
                $convocatoria->actualizado_por = $user_current["id"];
                $convocatoria->fecha_actualizacion = date("Y-m-d H:i:s");
                if($put["numero_estimulos"]=="")
                {
                    unset($put["numero_estimulos"]);
                }
                if($put["cantidad_perfil_jurado"]=="")
                {
                    unset($put["cantidad_perfil_jurado"]);
                }
                if ($convocatoria->save($put) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit_categoria, error al editar la categoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();  
                    
                    echo "error";
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Convocatoriaspublicas en el método edit_categoria, edito con éxito la categoria"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    
                    echo $id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit_categoria, el usuario no tiene acceso"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();         
            
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit_categoria, token caduco"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
            $logger->close(); 
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Convocatoriaspublicas en el método edit_categoria, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        $logger->close();         
        echo "error_metodo";
    }
}
);













// Recupera todos las areas seleccionados de un usuario determinado
$app->get('/select_user/{id:[0-9]+}', function ($id) use ($app, $config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
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
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
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



// Eliminar registro
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

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

//Busca el registro
$app->get('/search', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
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
            Ejemplo claro para traer todos los hijos de una entidad
            $convocatoria->Convocatorias
            */

            $array["programas"]= Programas::find("active=true");
            //$array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id WHERE Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");
            //cesar britto
            $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id WHERE Tiposparticipantes.active=true");
            $array["coberturas"]= Coberturas::find("active=true");
            $array["localidades"]= Localidades::find("active=true");
            $array["upzs"]=array();
            $array["barrios"]=array();
            if(isset($convocatoria->id))
            {
                $array["modalidades"]= Modalidades::find("active=true AND programa=".$convocatoria->programa);
                
                $array["enfoques"]= Enfoques::find("active=true AND programa=".$convocatoria->programa);
                
                /*
                $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id AND Convocatoriasparticipantes.convocatoria= ".$convocatoria->id." WHERE /Tiposparticipantes.active=true AND Tiposparticipantes.id <> 4");*/
                //cesar britto
                  $array["tipos_participantes"] = $app->modelsManager->executeQuery("SELECT Tiposparticipantes.id,Tiposparticipantes.nombre,Convocatoriasparticipantes.active,Convocatoriasparticipantes.descripcion_perfil AS descripcion_cp,Convocatoriasparticipantes.id AS id_cp  FROM Tiposparticipantes LEFT JOIN Convocatoriasparticipantes ON Convocatoriasparticipantes.tipo_participante = Tiposparticipantes.id AND Convocatoriasparticipantes.convocatoria= ".$convocatoria->id." WHERE Tiposparticipantes.active=true");

                $array["perfiles_jurados"]= Convocatoriasparticipantes::find(['convocatoria = '.$convocatoria->id.' AND tipo_participante=4','order' => 'orden']);
                if(isset($convocatoria->localidad))
                {
                    $array["upzs"]= Upzs::find("active=true AND localidad=".$convocatoria->localidad);
                    $array["barrios"]= Barrios::find("active=true AND localidad=".$convocatoria->localidad);
                }
                $array["categorias"]= Convocatorias::find(['convocatoria_padre_categoria = '.$convocatoria->id.' AND active=TRUE','order' => 'nombre']);
            }            
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active=true");
            $array["areas"]= Areas::find("active=true");
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='cantidad_perfil_jurado'");
            $array["cantidad_perfil_jurados"] = explode(",", $tabla_maestra[0]->valor);
            $array["tipos_convenios"]= Tiposconvenios::find("active=true");
            $array["tipos_estimulos"]= Tiposestimulos::find("active=true");
            $array["entidades"]= Entidades::find("active=true");
            $array["areas_conocimientos"]= Areasconocimientos::find("active=true AND id<>9");
            $array["niveles_educativos"]= Niveleseducativos::find("active=true");
            $array["estados"]= Estados::find("active=true AND tipo_estado='convocatorias' ORDER BY orden");
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
            for($i = date("Y")+1; $i >= 2016; $i--){
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



//Modulo buscador
$app->get('/modulo_buscador_propuestas', function () use ($app, $config, $logger){
    
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
        
    try {        
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo modulo_buscador_propuestas con el fin de cargar el formulario de busqueda"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
            
            
            $array=array();
            for($i = date("Y"); $i >= 2016; $i--){
                $array["anios"][] = $i;
            }
            $array["entidades"]= Entidades::find("active = true");
            $array["areas"]= Areas::find("active = true");
            $array["lineas_estrategicas"]= Lineasestrategicas::find("active = true");
            $array["programas"]= Programas::find("active = true");
            $array["enfoques"]=Enfoques::find("active = true");
            $array["estados_propuestas"] = Estados::find(
                                                            array(
                                                                "tipo_estado = 'propuestas' AND active = true",
                                                                "order" => "orden"
                                                            )
                                                            );            
            
            $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información en el metodo modulo_buscador_propuestas con el fin de cargar el formulario de busqueda"', ['user' => '', 'token' => $request->get('token')]);
            
            echo json_encode($array);
                        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo modulo_buscador_propuestas para cargar el formulario de busqueda"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
            
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo modulo_buscador_propuestas para cargar el formulario de busqueda"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo modulo_buscador_propuestas para cargar el formulario de busqueda' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
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
        if (isset($token_actual->id)) {

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
        if (isset($token_actual->id)) {

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

// Recupera todas las convocatorias y categorias
$app->get('/select_convocatoria_categorias', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
           
            $convocatorias = Convocatorias::find("id=".$request->get('id')." OR convocatoria_padre_categoria=".$request->get('id')."");
            $json_convocatorias=array();
            foreach ($convocatorias as $convocatoria) {
                $json_convocatorias[] = array("id"=>$convocatoria->id,"nombre"=>$convocatoria->nombre);
            }
            
            echo json_encode($json_convocatorias);
        }
        else
        {
            echo "error_token";
        }
    } catch (Exception $ex) {
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
