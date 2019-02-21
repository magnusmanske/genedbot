# Install
* Make sure PHP7 is installed
* Get [Composer](https://getcomposer.org/download/)
* Run composer with "update" command to install dependencies
* Create a `bot.ini` file, like this:
```
[user]
user = WikidataBotUserName
pass = WikidataBotUserPassword
```

# Run
```
php genedbot.php SPECIES_ID
```
where `SPECIES_ID` is an `abbreviation` in [the dataset](https://www.genedb.org/data/datasets.json).
