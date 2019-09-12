<?php
use Phalcon\Mvc\Model;

class Educacionnoformal extends Model
{
    public $id;

    public function initialize()
    {
        //belongsTo	Defines a n-1 relationship
        $this->hasMany(
            'usuario_perfil',
            'Usuariosperfiles',
            'id'
        );

        //belongsTo	Defines a n-1 relationship
        $this->hasMany(
            'propuesta',
            'Propuestas',
            'id'
        );
    }

     public function validation(){
       /*

       if ($this->area_conocimiento == "") {
         $this->area_conocimiento = null;
       }


       if ($this->nucleo_basico == "") {
         $this->nucleo_basico = null;
       }
       */
       return true;
     }


}
