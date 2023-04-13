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
        "skip-image-paths": [
            "cache",
            "temp"
        ]
    }
}
```

This will exclude `$IP/images/cache` and `$IP/images/temp` from back-up.

Also, you may want to exclude some data tables. It can be done that way:
```
{
    "db-options": {
        "skip-tables: [
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