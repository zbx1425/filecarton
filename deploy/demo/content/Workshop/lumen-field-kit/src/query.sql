-- Original demo code, CC0.
-- Bright-star notebook. Magnitudes are approximate naked-eye values.

create table stars (
  name text primary key,
  bayer text not null,
  magnitude real not null,
  constellation text not null
);

insert into stars (name, bayer, magnitude, constellation) values
  ('Sirius', 'alpha CMa', -1.46, 'Canis Major'),
  ('Canopus', 'alpha Car', -0.74, 'Carina'),
  ('Vega', 'alpha Lyr', 0.03, 'Lyra'),
  ('Altair', 'alpha Aql', 0.76, 'Aquila');

select name, magnitude
from stars
where constellation in ('Lyra', 'Aquila')
order by magnitude;
