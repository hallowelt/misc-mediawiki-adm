# MWStake MediaWiki CLI Admin

## Backup a MediaWiki instance

    mediawiki-adm wiki-backup \
        --mediawiki-root /var/www/mywiki \
        --dest /mnt/backup/

## Restore a MediaWiki instance

```
mediawiki-adm wiki-backup \
    --mediawiki-root /var/www/mywiki \
    --src /mnt/backup/mywiki-20200916131745.zip
```

### Overriding import settings

If you want to import in a different database (e.g. for creating a test system) you can use the `--profile` parameter:

```
mediawiki-adm wiki-backup \
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