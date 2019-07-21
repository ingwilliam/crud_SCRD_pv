<?php
use Phalcon\Mvc\Model;

class Participantes extends Model
{
    public $id;

    //Cesar Britto
    public function initialize()
   {

      //hasOne	Defines a 1-1 relationship
       $this->hasOne(
           'usuario_perfil',
           'Usuariosperfiles',
           'id'
       );

       //hasMany	Defines a 1-n relationship
       $this->hasMany(
           'id',
           'Propuestas',
           'participante'
       );
   }
}
