<?php
use Phalcon\Mvc\Model;

class Recursosnopecuniarios extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Convocatorias recursos
        $this->hasMany(
            'id',
            'Convocatoriasrecursos',
            'recurso_no_pecuniario'
        );                        
    } 
    
}