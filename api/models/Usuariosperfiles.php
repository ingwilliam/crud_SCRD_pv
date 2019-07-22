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

   }

}
