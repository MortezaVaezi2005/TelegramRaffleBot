# Async ORM

[![codecov](https://codecov.io/gh/danog/AsyncOrm/branch/master/graph/badge.svg)](https://codecov.io/gh/danog/AsyncOrm)
[![Psalm coverage](https://shepherd.dev/github/danog/AsyncOrm/coverage.svg)](https://shepherd.dev/github/danog/AsyncOrm)
[![Psalm level 1](https://shepherd.dev/github/danog/AsyncOrm/level.svg)](https://shepherd.dev/github/danog/AsyncOrm)
![License](https://img.shields.io/github/license/danog/AsyncOrm)

Async ORM based on AMPHP v3 and fibers, created by Daniil Gentili (https://daniil.it) and Alexander Pankratov (alexander@i-c-a.su).  

Supports MySQL, Redis, Postgres.  

Features read and write-back caching, type-specific optimizations, and much more!  

This ORM library was initially created for [MadelineProto](https://docs.madelineproto.xyz), an async PHP client API for the telegram MTProto protocol.  

## Installation

```bash
composer require danog/async-orm
```

## Usage

There are two main ways to use the ORM: through automatic ORM properties, which automatically connects appropriately marked `DbArray` properties to the specified database, or by manually instantiating a `DbArray` with a `DbArrayBuilder`.

* [Automatic ORM properties example &raquo;](https://github.com/danog/AsyncOrm/blob/master/examples/1-automatic.php)
* [Manual example &raquo;](https://github.com/danog/AsyncOrm/blob/master/examples/2-manual.php)

The `DbArray` obtained through one of the methods above is an abstract array object that automatically stores and fetches elements of the specified [type &raquo;](#value-types), from the specified database.  

`DbArray`s of type `ValueType::OBJECT` can contain objects extending `DbObject`.  

Classes extending `DbObject` have a special `save` method that can be used to persist object changes to the database, as can be seen in the [example](https://github.com/danog/AsyncOrm/blob/master/examples/2-manual.php).  

### Settings

As specified in the examples above, there are multiple settings classes that can be used to connect to a specific database type:  

* [MysqlSettings: MySQL backend settings.](https://github.com/danog/AsyncOrm/blob/master/docs/docs/danog/AsyncOrm/Settings/MysqlSettings.md)
* [PostgresSettings: Postgres backend settings.](https://github.com/danog/AsyncOrm/blob/master/docs/docs/danog/AsyncOrm/Settings/PostgresSettings.md)
* [RedisSettings: Redis backend settings.](https://github.com/danog/AsyncOrm/blob/master/docs/docs/danog/AsyncOrm/Settings/RedisSettings.md)

All these classes have multiple fields, described in their respective documentation (click on each class name to view it).  

#### Caching

One of the most important settings is the `cacheTtl` field, which specifies the duration of the read and write cache.  

If non-zero, all array elements fetched from the database will be stored in an in-memory *read cache* for the specified number of seconds; multiple accesses to the same field will each postpone flushing of that field by `cacheTtl` seconds.  

All elements written to the array by the application will also be stored in an in-memory *write cache*, and flushed to the database every `cacheTtl` seconds.  

If the array has an [object value type (ValueType::OBJECT)](#key-and-value-types), write caching is disabled.  

If `cacheTtl` is 0, read and write caching is disabled.  

A special setting class is used to create `DbArray`s backed by no database, which can also be useful in certain circumstances:  

* [MemorySettings: MemorySettings backend settings.](https://github.com/danog/AsyncOrm/blob/master/docs/docs/danog/AsyncOrm/Settings/MemorySettings.md)


### Key and value types

Each DbArray must have a specific key and value type.  

For optimal performance, the specified types must be as strict as possible, here's a list of allowed types:  

#### Key types

* `KeyType::STRING` - String keys only
* `KeyType::INT` - Integer keys only
* `KeyType::STRING_OR_INT` - String or integer keys (not recommended, for performance reasons please always specify either `STRING` or `STRING_OR_INT`).  

#### Value types

* `ValueType::STRING`: Direct storage of UTF-8 string values.
* `ValueType::INT`: Direct storage of integer values.
* `ValueType::BOOL`: Direct storage of boolean values.
* `ValueType::FLOAT`: Direct storage of floating point (double precision) values.
* `ValueType::SCALAR`: Values of any scalar type (including arrays, excluding objects), serialized as specified in the settings.
   Using SCALAR worsens performances, please use any of the other types if possible.
* `ValueType::OBJECT`: Objects extending DbObject, serialized as specified in the settings.

One of the most important value types is `ValueType::OBJECT`, it is used to store entire objects extending the `DbObject` class to the database.  

Objects extending `DbObject` have a special `save` method that can be used to persist object changes to the database, as can be seen in the [example](https://github.com/danog/AsyncOrm/blob/master/examples/2-manual.php).  

## API Documentation

Click [here &raquo;](https://github.com/danog/AsyncOrm/blob/master/docs/docs/index.md) to view the API documentation.
