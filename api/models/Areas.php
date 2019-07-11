<?php
use Phalcon\Mvc\Model;

class Areas extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Convocatorias',
            'area'
        );                        
    }
    
}