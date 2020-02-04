<?php
use Phalcon\Mvc\Model;

class Usuariosperfiles extends Model
{
    public $id;

    //Cesar Britto
    public function initialize()
   {
    //hasOne	Defines a 1-1 relationship
     $this->hasOne(
         'id',
         'Participantes',
         'usuario_perfil'
     );


     //hasMany	Defines a 1-n relationship
     $this->hasMany(
         'id',
         'Educacionformal',
         'usuario_perfil'
     );
     
    //Se define relacion de N a 1 con Perfiles
    $this->belongsTo(
        'perfil',
        'Perfiles',
        'id'
    );
    
    //Se define relacion de N a 1 con Paises
    $this->belongsTo(
        'usuario',
        'Usuarios',
        'id'
    );

   }

}
