<?php
use Phalcon\Mvc\Model;

class Estados extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Convocatorias',
            'estado'
        );                        
    }
}