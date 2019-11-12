<?php
use Phalcon\Mvc\Model;

class Propuestas extends Model
{
    public $id;

    public function initialize()
    {
        //hasOne	Defines a 1-1 relationship
        $this->hasOne(
            'participante',
            'Participantes',
            'id'
        );

        //hasOne	Defines a 1-1 relationship
        $this->hasOne(
            'convocatoria',
            'Convocatorias',
            'id'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Educacionformal',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Educacionnoformal',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Experiencialaboral',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Experienciajurado',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Propuestajuradoreconocimiento',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Propuestajuradopublicacion',
            'propuesta'
        );

        //hasMany 	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Propuestajuradodocumento',
            'propuesta'
        );


        //hasMany	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Juradospostulados',
            'propuesta'
        );
        
        //23 oct 2019->William Barbosa
        //Se define relacion de N a 1 con estado
        $this->belongsTo(
            'estado',
            'Estados',
            'id'
        );

        //hasMany	Defines a 1-n relationship
        $this->hasMany(
            'id',
            'Evaluacion',
            'propuesta'
        );

    }


}
