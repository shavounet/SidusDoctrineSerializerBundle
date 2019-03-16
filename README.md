Sidus/DoctrineSerializerBundle Documentation
==================================

This bundle allows you to denormalize Doctrine entities by fetching them from the database using either their
primary key(s) or a set of unique properties if defined in the mapping.

Basically, when denormalizing an entity, it will try to fetch an existing entity from database before updating
it with normalized data.
