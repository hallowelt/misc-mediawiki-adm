# MWStake MediaWiki CLI Admin

## Backup a MediaWiki instance

    mediawiki-adm wiki-backup \
        --mediawiki-root /var/www/mywiki \
        --dest /mnt/backup/

## Restore a MediaWiki instance

```
mediawiki-adm wiki-restore \
    --mediawiki-root /var/www/mywiki \
    --src /mnt/backup/mywiki-20200916131745.zip
```

### Overriding import settings

If you want to import in a different database (e.g. for creating a test system) you can use the `--profile` parameter:

```
mediawiki-adm wiki-restore \
    --mediawiki-root /var/www/mywiki \
    --src /mnt/backup/mywiki-20200916131745.zip \
    --profile testsystem.json
```

With the contents of `testsystem.json` to be

```
{
	"db-options": {
		"connection": {
			"dbserver": "testdbserver",
			"dbuser": "testdbuser",
			"dbpassword": "testdbpassword"
		},
		"skip-tables-data": [ "job" ]
	},
	"fs-options": {
		"overwrite-newer": true
	}
}
```

### Overriding back-up settings

If you want to specify some image folders to skip when making back-up you can use the `--profile` parameter:

```
mediawiki-adm wiki-backup \
    --mediawiki-root /var/www/mywiki \
    --dest /mnt/backup/ \
    --profile some-profile.json
```

With the contents of `some-profile.json` to be

```
{
    "fs-options": {
        "include-custom-paths": [
            "data",
            "meta.json"
        ],
        "skip-image-paths": [
            "cache",
            "temp"
        ]
    }
}
```

This will include `$IP/data` and `$IP/meta.json` to and exclude `$IP/images/cache` and `$IP/images/temp` from back-up.

Also, you may want to exclude some data tables. It can be done that way:
```
{
    "db-options": {
        "skip-tables": [
            "object_cache",
            "l10n_cache"
        ]
    },
    "fs-options": {
        "skip-image-paths": [
            "cache",
            "temp"
        ]
    }
}
```


### Backing up farming instance

Important: Database connection params, whether read from setting file or specified in `profile`, must refer to the
main (`w`) wiki database, as that DB holds information on all instances.

Commands are the same as for single wiki, but additional parameters in `profile.json` are required
- Set all parameters as if backing up/restoring the main farm wiki (`w`, management instance)
- Add section `bluespice-farm-options` that has following items

```
{
    "bluespice-farm-options": {
        "instances-dir": "/var/www/w/_sf_instance",
        "instance-name": "wiki-name"
    }
}
```

Note: `instances-dir` - path to the root directory that holds instances - optional, if not set, it will use `--mediawiki-root/_sf_instances/`

#### Backing up whole farm
    
    mediawiki-adm wiki-backup \
        --mediawiki-root /var/www/w \
        --dest /mnt/backup/

with `profile.json`:

```
{
    "db-options": {
		"connection": {
			"dbserver": "testdbserver",
			"dbuser": "testdbuser",
			"dbpassword": "testdbpassword"
			"dbname": "w"
		}
	},  
    "bluespice-farm-options": {
        "instance-name": "*"
    }
}
```

will export all active instance of the farm, and then export `w` itself

### Restore wiki farm instance

**Only instances that were backed up using this tool can be restored safely!**

Since instance settings are stored in DB, when backing up, extra file `filesystem/settings.json` will be generated,
which is then used on restore.

When restoring a farm instance, profile file __must__ be used, with `db-options.connection` set
to the main wiki database. Optionally, set `bluespice-farm-options.instances-dir` to the root directory that holds instances.

```json
{
	"db-options": {
		"connection": {
			"dbserver": "127.0.0.1",
			"dbuser": "root",
			"dbpassword": "...",
			"dbname": "w"
		}
	},
    "bluespice-farm-options": {
        "instances-dir": "/path/to/instances"
    }
}
```

