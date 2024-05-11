
## Using either the APC or APCng storage engine:
```php
$registry = new CollectorRegistry(new APCng());
 // or...
$registry = new CollectorRegistry(new APC());

// then...
$counter = $registry->registerCounter('test', 'some_counter', 'it increases', ['type']);
$counter->incBy(3, ['blue']);

$renderer = new RenderTextFormat();
$result = $renderer->render($registry->getMetricFamilySamples());
```

## Performance comparions vs the original APC engine
The difference between `APC` and `APCng` is that `APCng` is re-designed for servers which have millions of entries in their APCu cache and/or receive hundreds to thousands of requests per second. Several key data structures in the original `APC` engine require repeated scans of the entire keyspace, which is far too slow and CPU-intensive for a busy server when APCu contains more than a few thousand keys. `APCng` avoids these scans for the most part, the trade-off being creation of new metrics is slightly slower than it is with the `APC` engine, while other operations are approximately the same speed, and collecting metrics to report is 1-2 orders of magnitude faster when APCu contains 10,000+ keys.
In general, if your APCu cache contains over 1000 keys, consider using the `APCng` engine.
In my testing, on a system with 100,000 keys in APCu and 500 Prometheus metrics being tracked, rendering all metrics took 35.7 seconds with the `APC` engine, but only 0.6 seconds with the `APCng` engine. Even with a tiny cache (50 metrics / 1000 APC keys), `APCng` is over 2.5x faster generating reports. As the number of APCu keys and/or number of tracked metrics increases, `APCng`'s speed advantage grows.
The following table compares `APC` and `APCng` processing time for a series of operations, including creating each metric, incrementing each metric, the wipeStorage() call, and the collect() call, which is used to render the page that Prometheus scrapes. Lower numbers are better!  Increment is the most frequently used operation, followed by collect, which happens every time Prometheus scrapes the server. Create and wipe are relatively infrequent operations.

| Configuration                  | Create (ms) | Increment (ms) | WipeStorage (ms) | Collect (ms) | Collect speedup over APC |
|--------------------------------|------------:|---------------:|-----------------:|-------------:|-------------------------:|
| APC 1k keys     / 50 metrics   |         n/t |            n/t |              n/t |         29.0 |                        - |
| APC 10k keys    / 50 metrics   |         9.2 |            0.7 |              1.1 |        131.9 |                        - |
| APC 100k keys   / 50 metrics   |         9.3 |            1.3 |             11.9 |       3474.1 |                        - |
| APC 1M keys     / 50 metrics   |        12.7 |            1.4 |             19.2 |       4805.8 |                        - |
| APC 1k keys     / 500 metrics  |         n/t |            n/t |              n/t |        806.5 |                        - |
| APC 10k keys    / 500 metrics  |        26.7 |            9.3 |              4.2 |       1770.9 |                        - |
| APC 100k keys   / 500 metrics  |        44.8 |           13.1 |             16.6 |      35758.3 |                        - |
| APC 1M keys     / 500 metrics  |        39.9 |           25.9 |             22.9 |      46489.1 |                        - |
| APC 1k keys     / 2500 metrics |         n/t |            n/t |              n/t |          n/t |                      n/t |
| APC 10k keys    / 2500 metrics |       196.7 |           95.1 |             17.6 |      24689.5 |                        - |
| APC 100k keys   / 2500 metrics |       182.6 |           82.0 |             34.4 |     216526.5 |                        - |
| APC 1M keys     / 2500 metrics |       172.7 |           93.3 |             38.3 |     270596.3 |                        - |
|                                |             |                |                  |              |                          |
| APCng 1k keys   / 50 metrics   |         n/t |            n/t |              n/t |         11.1 |                     2.6x |
| APCng 10k keys  / 50 metrics   |         8.6 |            0.6 |              1.3 |         15.2 |                     8.6x |
| APCng 100k keys / 50 metrics   |        10.1 |            1.0 |             11.7 |         69.7 |                    49.8x |
| APCng 1M keys   / 50 metrics   |        10.4 |            1.3 |             17.3 |        100.4 |                    47.9x |
| APCng 1k keys   / 500 metrics  |         n/t |            n/t |              n/t |        108.3 |                     7.4x |
| APCng 10k keys  / 500 metrics  |        25.2 |            7.2 |              5.9 |        118.6 |                    14.9x |
| APCng 100k keys / 500 metrics  |        55.0 |           12.3 |             18.6 |        603.9 |                    59.2x |
| APCng 1M keys   / 500 metrics  |        39.9 |           14.1 |             22.9 |        904.2 |                    51.4x |
| APCng 1k keys   / 2500 metrics |         n/t |            n/t |              n/t |          n/t |                      n/t |
| APCng 10k keys  / 2500 metrics |       181.3 |           80.3 |             17.9 |        978.8 |                    25.2x |
| APCng 100k keys / 2500 metrics |       274.7 |           84.0 |             34.6 |       4092.4 |                    52.9x |
| APCng 1M keys   / 2500 metrics |       187.8 |           87.7 |             40.7 |       5396.4 |                    50.1x |

The suite of engine-performance tests can be automatically executed by running `docker-compose run phpunit vendor/bin/phpunit tests/Test --group Performance`. This set of tests in not part of the default unit tests which get run, since they take quite a while to complete. Any significant change to the APC or APCng code should be followed by a performance-test run to quantify the before/after impact of the change. Currently this is triggered manually, but it could be automated as part of a Github workflow.

## Known limitations
One thing to note, the current implementation of the `Summary` observer should be avoided on busy servers. This is true for both the `APC` and `APCng` storage engines. The reason is simple: each observation (call to increment, set, etc) results in a new item being written to APCu. The default TTL for these items is 600 seconds.  On a busy server that might be getting 1000 requests/second, that results in 600,000 APC cache items continually churning in and out of existence.  This can put some interesting pressure on APCu, which could lead to rapid fragmentation of APCu memory. Definitely test before deploying in production.

For a future project, the existing algorithm that stores one new key per observation could be replaced with a sampling-style algorithm (`t-digest`) that only stores a handful of keys, and updates their weights for each request. This is considerably less likely to fragment APCu memory over time.

Neither the `APC` or `APCng` engine performs particularly well once more than ~1000 Prometheus metrics are being tracked.  Of course, "good performance" is subjective, and partially based on how often you scrape for data.  If you only scrape every five minutes, then spending 4 seconds waiting for collect() might be perfectly acceptable.  On the other hand, if you scrape every 2 seconds, you'll want collect() to be as fast as possible.

## How it works under the covers
Without going into excruciating detail (you can read the source for that!), the general idea is to remove calls to APCUIterator() whenever possible. In particular, nested calls to APCUIterator are horrible, since APCUIterator scales O(n) where n is the number of keys in APCu.  This means the busier your server is, the slower these calls will run.  Summary is the worst: it has APCUIterator calls nested three deep, leading to O(n^3) running-time.

The approach `APCng` takes is to keep a "metadata cache" which stores an array of all the metadata keys, so instead of doing a scan of APCu looking for all matching keys, we just need to retrieve one key, deserialize it (which turns out to be slow), and retrieve all the metadata keys listed in the array.  Once we've done that, there is some fancy handwaving which is used to deterministically generate possible sub-keys for each metadata item, based on LabelNames, etc. Not all of these keys exist, but it's quicker to attempt to fetch them and fail, then it is to run another APCUIterator looking for a specific pattern.

Summaries, as mentioned before, have a third nested APCUIterator in them, looking for all readings w/o expired TTLs that match a pattern.  Again, slow.  Instead, we store a "map", similar to the metadata cache, but this one is temporally-keyed: one key per second, which lists how many samples were collected in that second. Once this is done, an expensive APCUIterator match is no longer needed, as all possible keys can be deterministically generated and checked, by retrieving each key for the past 600 seconds (if it exists), extracting the sample-count from the key, and then generating all the APCu keys which would refer to each observed sample.
