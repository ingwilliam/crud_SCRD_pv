<?php
use Phalcon\Mvc\Model;

class Educacionformal extends Model
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
    }
    
}
