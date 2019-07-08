<?php
use Phalcon\Mvc\Model;

class Convocatoriasrecursos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Convocatorias
        $this->belongsTo(
            'convocatoria',
            'Convocatorias',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define relacion de N a 1 con Convocatoria Recursos NO OBLIGATORIA
        $this->belongsTo(
            'recurso_no_pecuniario',
            'Recursosnopecuniarios',
            'id'            
        );
        
    } 
    
}