CREATE TABLE public.paises
(
id integer NOT NULL DEFAULT nextval('paises_id_seq'::regclass),
nombre character varying(160),
active boolean,
fecha_actualizacion timestamp without time zone,
fecha_creacion timestamp without time zone,
creado_por integer,
actualizado_por integer,
CONSTRAINT paises_pkey PRIMARY KEY (id),
CONSTRAINT fk_actualizado FOREIGN KEY (actualizado_por)
REFERENCES public.usuarios (id) MATCH SIMPLE
ON UPDATE NO ACTION ON DELETE NO ACTION,
CONSTRAINT fk_creado FOREIGN KEY (creado_por)
REFERENCES public.usuarios (id) MATCH SIMPLE
ON UPDATE NO ACTION ON DELETE NO ACTION
)


CREATE TABLE public.departamentos
(
  id serial,
  nombre character varying(160),
  active boolean,
  fecha_actualizacion timestamp without time zone,
  fecha_creacion timestamp without time zone,
  creado_por integer,
  actualizado_por integer,
  pais integer,
  CONSTRAINT departamentos_pkey PRIMARY KEY (id),
  CONSTRAINT fk_creado FOREIGN KEY (creado_por)
      REFERENCES public.usuarios (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT fk_pais FOREIGN KEY (pais)
      REFERENCES public.paises (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION
)

CREATE TABLE public.ciudades
(
  id integer NOT NULL DEFAULT nextval('ciudades_id_seq'::regclass),
  nombre character varying(160),
  active boolean,
  fecha_actualizacion timestamp without time zone,
  fecha_creacion timestamp without time zone,
  creado_por integer,
  actualizado_por integer,
  departamento integer,
  CONSTRAINT ciudades_pkey PRIMARY KEY (id),
  CONSTRAINT fk_creado FOREIGN KEY (creado_por)
      REFERENCES public.usuarios (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT fk_departamento FOREIGN KEY (departamento)
      REFERENCES public.departamentos (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION
)
CREATE TABLE public.localidades
(
  id integer NOT NULL DEFAULT nextval('localidades_id_seq'::regclass),
  nombre character varying(160),
  active boolean,
  fecha_actualizacion timestamp without time zone,
  fecha_creacion timestamp without time zone,
  creado_por integer,
  actualizado_por integer,
  ciudad integer,
  CONSTRAINT localidades_pkey PRIMARY KEY (id),
  CONSTRAINT fk_ciudad FOREIGN KEY (ciudad)
      REFERENCES public.ciudades (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT fk_creado FOREIGN KEY (creado_por)
      REFERENCES public.usuarios (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION
)

CREATE TABLE public.upz
(
  id integer NOT NULL DEFAULT nextval('upz_id_seq'::regclass),
  nombre character varying(160),
  active boolean,
  fecha_actualizacion timestamp without time zone,
  fecha_creacion timestamp without time zone,
  creado_por integer,
  actualizado_por integer,
  localidad integer,
  CONSTRAINT upz_pkey PRIMARY KEY (id),
  CONSTRAINT creado_fk FOREIGN KEY (creado_por)
      REFERENCES public.usuarios (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT localidad_fk FOREIGN KEY (localidad)
      REFERENCES public.localidades (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION
)

CREATE TABLE public.ciudades
(
  id integer NOT NULL DEFAULT nextval('ciudades_id_seq'::regclass),
  nombre character varying(160),
  active boolean,
  fecha_actualizacion timestamp without time zone,
  fecha_creacion timestamp without time zone,
  creado_por integer,
  actualizado_por integer,
  departamento integer,
  CONSTRAINT ciudades_pkey PRIMARY KEY (id),
  CONSTRAINT fk_creado FOREIGN KEY (creado_por)
      REFERENCES public.usuarios (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION,
  CONSTRAINT fk_departamento FOREIGN KEY (departamento)
      REFERENCES public.departamentos (id) MATCH SIMPLE
      ON UPDATE NO ACTION ON DELETE NO ACTION
)