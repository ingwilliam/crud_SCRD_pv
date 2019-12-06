<?php
use Phalcon\Mvc\Model;

class Propuestaslinks extends Model
{
    public $id;
    
    public function initialize()
    {                
        //Se define relacion de N a 1 con Propuestas
        $this->belongsTo(
            'propuesta',
            'Propuestas',
            'id'
        );
        
        //Se define relacion de N a 1 con Convocatoriasdocumentos
        $this->belongsTo(
            'convocatoriadocumento',
            'Convocatoriasdocumentos',
            'id'
        );

    }    

}