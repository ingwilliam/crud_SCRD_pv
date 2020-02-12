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

// Recupera todos los registros
$app->post('/iniciar_session', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Solicita acceso al sistema para iniciar sesión ', ['user' => $this->request->getPost('username'), 'token' => '']);

        //Consulto el usuario por username del parametro get
        $usuario_validar = Usuarios::findFirst("username = '" . $this->request->getPost('username') . "'");

        //Valido si existe
        if (isset($usuario_validar->id)) {
            //Valido si la clave es igual al token del usuario
            if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {
                //Fecha actual
                $fecha_actual = date("Y-m-d H:i:s");
                //Fecha limite de videgncia del token de de acceso
                $fecha_limit = date("Y-m-d H:i:s", strtotime('+' . $config->database->time_session . ' minute', strtotime($fecha_actual)));

                //Consulto y elimino todos los tokens que ya no se encuentren vigentes
                $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
                $tokens_eliminar->delete();

                //Elimino el token del usuario
                unset($usuario_validar->password);
                //Creo el token de acceso para el usuario solicitado, con vigencia del valor configurado en el $config time_session
                $tokens = new Tokens();
                $tokens->token = $this->security->hash($usuario_validar->id . "-" . $usuario_validar->tipo_documento . "-" . $usuario_validar->numero_documento);
                $tokens->user_current = json_encode($usuario_validar);
                $tokens->date_create = $fecha_actual;
                $tokens->date_limit = $fecha_limit;
                $tokens->save();

                //Genero el array que retornare como json, para el manejo del localStorage en el cliente
                $token_actual = array("token" => $tokens->token, "usuario" => $usuario_validar->primer_nombre . " " . $usuario_validar->segundo_nombre . " " . $usuario_validar->primer_apellido . " " . $usuario_validar->segundo_apellido);

                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al sistema, los datos de acceso son los correctos.', ['user' => $this->request->getPost('username'), 'token' => $tokens->token]);
                $logger->close();

                echo json_encode($token_actual);
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El password es incorrecto', ['user' => $this->request->getPost('username'), 'token' => '']);
                $logger->close();
                echo "error_clave";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no se encuentra registrado', ['user' => $this->request->getPost('username'), 'token' => '']);
            $logger->close();
            echo "error_usuario";

        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_participante ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Recupera todos los registros
$app->post('/consultar_usuario', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingreso a consultar_usuario ', ['user' => $this->request->getPost('username'), 'token' => '']);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);

            if (isset($user_current["id"])) {

                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo consultar_usuario"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();

                echo json_encode($user_current);
                exit;
            }
            else {
                   //Registro la accion en el log de convocatorias
                   $logger->error('"token":"{token}","user":"{user}","message":"Error el usuario no existe en la base de datos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                   $logger->close();
                   echo "error";
                   exit;
               }
        }
        else
        {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo consultar_usuario ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Recupera todos los registros
$app->post('/recordar_usuario', function () use ($app, $config) {

    try {
        //Consulto el usuario por username del parametro get
        $usuario_validar = Usuarios::findFirst("username = '" . $this->request->getPost('username') . "'");

        //Valido si existe
        if (isset($usuario_validar->id)) {
            $usuario_validar->password = $this->security->hash(date("Ymd"));
            if ($usuario_validar->save() === false) {
                echo "error_editar";
            } else {
                //Creo el cuerpo del messaje html del email
                $html_recordar_usuario = Tablasmaestras::find("active=true AND nombre='html_recordar_usuario'")[0]->valor;
                $html_recordar_usuario = str_replace("**password**", date("Ymd"), $html_recordar_usuario);

                $mail = new PHPMailer();
                $mail->IsSMTP();
                $mail->SMTPAuth = true;
                $mail->Host = "smtp.gmail.com";
                $mail->SMTPSecure = 'ssl';
                $mail->Username = "convocatorias@scrd.gov.co";
                $mail->Password = "fomento2017";
                $mail->Port = 465;
                $mail->CharSet = "UTF-8";
                $mail->IsHTML(true); // El correo se env  a como HTML
                $mail->From = "convocatorias@scrd.gov.co";
                $mail->FromName = "Sistema de Convocatorias";
                $mail->AddAddress($this->request->getPost('username'));
                $mail->Subject = "Sistema de Convocatorias - Recordar Contraseña";
                $mail->Body = $html_recordar_usuario;

                $exito = $mail->Send(); // Env  a el correo.

                if ($exito) {
                    echo "exito";
                } else {
                    echo "error_email";
                }
            }
        } else {
            echo "error_usuario";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

$app->get('/validar_actualizacion_codigo/{id:[0-9]+}', function ($id) use ($app, $config) {
    echo "WMX2 11 febrero 2020";
});

// Verifica el usuario
$app->get('/verificar_usuario/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Valido si existe el correo electronico
        $usuario_validar = Usuarios::findFirst("id = '" . $id . "'");
        if (isset($usuario_validar->id)) {
            if ($usuario_validar->active) {
                //Redireccionar
                header('Location: ' . $config->sistema->url_admin . 'index.html?msg=Se activó el usuario con éxito, por favor ingrese al sistema.....&msg_tipo=success');
                exit();
            } else {
                $usuario_validar->active = true;
                $usuario_validar->actualizado_por = "7";
                $usuario_validar->fecha_actualizacion = date("Y-m-d H:i:s");
                if ($usuario_validar->save() === false) {
                    //Redireccionar
                    header('Location: ' . $config->sistema->url_admin . 'index.html?msg=Se registro un error en el método, comuníquese con la mesa de ayuda soporte.convocatorias@scrd.gov.co&msg_tipo=danger');
                    exit();
                } else {
                    //Redireccionar
                    header('Location: ' . $config->sistema->url_admin . 'index.html?msg=Se activó el usuario con éxito, por favor ingrese al sistema.&msg_tipo=success');
                    exit();
                }
            }
        } else {
            //Redireccionar
            header('Location: ' . $config->sistema->url_admin . 'index.html?msg=No es un usuario valido, comuníquese con la mesa de ayuda soporte.convocatorias@scrd.gov.co&msg_tipo=danger');
            exit();
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});

// Crea el usuario
$app->post('/crear_usuario', function () use ($app, $config) {
    try {

        // your secret key
        $secret = "6LdwFnkUAAAAADBimwYjHGnZyPqRjkClp3183lVB";
        // empty response
        $response = null;
        // check secret key
        $reCaptcha = new ReCaptcha($secret);

        // if submitted check response
        if ($this->request->getPost('g-recaptcha-response')) {
            $response = $reCaptcha->verifyResponse($config->sistema->dominio, $this->request->getPost('g-recaptcha-response'));
        }

        if ($response != null && $response->success) {
            //Saco todos los parametros de post
            $post = $app->request->getPost();

            //Valido si existe el correo electronico
            $usuario_validar = Usuarios::findFirst("username = '" . $post["correo_electronico"] . "'");
            if (isset($usuario_validar->id)) {
                echo "error_username";
            } else {
                //Creo objeto usuario
                $usuario = new Usuarios();
                $usuario->active = false;
                $post["username"] = $post["correo_electronico"];
                $post["password"] = $this->security->hash($post["password"]);
                $post["creado_por"] = "7";
                $post["fecha_creacion"] = date("Y-m-d H:i:s");
                $post["key_verificacion"] = $key_verificacion;
                if ($usuario->save($post) === false) {
                    echo "error";
                } else {
                    $usuario_perfile = new Usuariosperfiles();
                    $array_new["usuario"] = $usuario->id;
                    $array_new["perfil"] = 16;
                    if ($usuario_perfile->save($array_new) === false) {
                        echo "error_perfil";
                    } else {

                        //Creo el cuerpo del messaje html del email
                        $html_solicitud_usuario = Tablasmaestras::find("active=true AND nombre='html_solicitud_usuario'")[0]->valor;
                        $html_solicitud_usuario = str_replace("**usuario**", $post["correo_electronico"], $html_solicitud_usuario);
                        $html_solicitud_usuario = str_replace("**srcverificacion**", $config->sistema->url_curl . "Session/verificar_usuario/" . $usuario->id, $html_solicitud_usuario);


                        $mail = new PHPMailer();
                        $mail->IsSMTP();
                        $mail->SMTPAuth = true;
                        $mail->Host = "smtp.gmail.com";
                        $mail->SMTPSecure = 'ssl';
                        $mail->Username = "convocatorias@scrd.gov.co";
                        $mail->Password = "fomento2017";
                        $mail->Port = 465;
                        $mail->CharSet = "UTF-8";
                        $mail->IsHTML(true); // El correo se env  a como HTML
                        $mail->From = "convocatorias@scrd.gov.co";
                        $mail->FromName = "Sistema de Convocatorias";
                        $mail->AddAddress($post["username"]);
                        $mail->Subject = "Sistema de Convocatorias - Verifición correo electrónico";
                        $mail->Body = $html_solicitud_usuario;

                        $exito = $mail->Send(); // Env  a el correo.

                        if ($exito) {
                            echo "exito";
                        } else {
                            echo "error_email";
                        }
                    }
                }
            }
        } else {
            echo "robot";
        }
    } catch (Exception $ex) {
        //echo "error_metodo";
          return "error_metodo" . $ex->getMessage().$ex->getTraceAsString ();
    }
}
);

// Permite verificar si el token esta activo
$app->post('/verificar_token', function () use ($app, $config) {

    try {
        //Fecha actual
        $fecha_actual = date("Y-m-d H:i:s");
        //Recupero el valor por post
        $token = $this->request->getPost('token');
        //Consulto y elimino todos los tokens que ya no se encuentren vigentes
        $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
        $tokens_eliminar->delete();
        //Consulto si el token existe y que este en el periodo de session
        $tokens = Tokens::findFirst("'" . $fecha_actual . "' BETWEEN date_create AND date_limit AND token = '" . $token . "'");
        //Verifico si existe para retornar
        if (isset($tokens->id)) {
            echo "ok";
        } else {
            echo "false";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

$app->get('/login_actions', function () use ($app, $config) {

    //Phalcon permite buscar directamente por nombre del campo, con metodo independiente
    //$user = Usuarios::findFirstByUsername("ingeniero.wb@gmail.com");
    //Consulto el usuario por username del parametro get
    $usuario_validar = Usuarios::findFirst("username = '" . $this->request->get('username') . "'");

    //Valido si existe
    if (isset($usuario_validar->id)) {
        //Valido si la clave es igual al token del usuario
        if ($this->security->checkHash($this->request->get('password'), $usuario_validar->password)) {

            //Fecha actual
            $fecha_actual = date("Y-m-d H:i:s");
            //Fecha limite de videgncia del token de de acceso
            $fecha_limit = date("Y-m-d H:i:s", strtotime('+' . $config->database->time_session . ' minute', strtotime($fecha_actual)));
            //Consulto y elimino todos los tokens que ya no se encuentren vigentes
            $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
            $tokens_eliminar->delete();

            //Elimino el token del usuario
            unset($usuario_validar->password);
            //Creo el token de acceso para el usuario solicitado, con vigencia del valor configurado en el $config time_session
            $tokens = new Tokens();
            $tokens->token = $this->security->hash($usuario_validar->id . "-" . $usuario_validar->tipo_documento . "-" . $usuario_validar->numero_documento);
            $tokens->user_current = json_encode($usuario_validar);
            $tokens->date_create = $fecha_actual;
            $tokens->date_limit = $fecha_limit;
            $tokens->save();
            echo $tokens->token;
        } else {
            echo "error";
        }
    } else {
        // To protect against timing attacks. Regardless of whether a user
        // exists or not, the script will take roughly the same amount as
        // it will always be computing a hash.
        //echo $this->security->hash(rand());
        echo "error";
    }
}
);

//Verifica permiso de lectura
$app->post('/permiso_lectura', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $user_current = json_decode($token_actual->user_current, true);

            //Consultar todos los permisos
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='" . $request->getPost('modulo') . "' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=" . $user_current["id"] . ")";
            $permisos = $app->modelsManager->executeQuery($phql);

            if (count($permisos) > 0) {
                echo "ok";
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex;
    }
}
);

//Verifica permiso de lectura
$app->post('/cerrar_session', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            if ($token_actual->delete() != false) {
                echo "ok";
            } else {
                echo "error";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex;
    }
}
);

//Verifica permiso de escritura para los permisos de control total y lectura e escritura
$app->post('/permiso_escritura', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $user_current = json_decode($token_actual->user_current, true);

            //Consultar todos los permisos
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='" . $request->getPost('modulo') . "' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=" . $user_current["id"] . ") AND mpp.permiso IN (1,2) ";
            $permisos = $app->modelsManager->executeQuery($phql);

            if (count($permisos) > 0) {
                echo "ok";
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex;
    }
}
);

//Verifica permiso de eliminar para los permisos de control total y lectura e escritura
$app->post('/permiso_eliminar', function () use ($app) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $user_current = json_decode($token_actual->user_current, true);

            //Consultar todos los permisos
            $phql = "SELECT mpp.* FROM Moduloperfilpermisos AS mpp "
                    . "INNER JOIN Modulos AS m ON m.id=mpp.modulo "
                    . "WHERE m.nombre='" . $request->getPost('modulo') . "' AND mpp.perfil IN (SELECT up.perfil FROM Usuariosperfiles AS up WHERE up.usuario=" . $user_current["id"] . ") AND mpp.permiso IN (1) ";
            $permisos = $app->modelsManager->executeQuery($phql);

            if (count($permisos) > 0) {
                echo "ok";
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex;
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
