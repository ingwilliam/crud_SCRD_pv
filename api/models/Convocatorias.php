<?php
use Phalcon\Mvc\Model;

class Convocatorias extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatoriasrecursos
        $this->hasMany(
            'id',
            'Convocatoriasrecursos',
            'convocatoria'
        );                        
    } 
    
}