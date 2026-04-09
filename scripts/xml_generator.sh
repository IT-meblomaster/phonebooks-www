#!/bin/bash
cd /var/www/phonebook.local/scripts
php generate_phonebooks.php /var/www/10.0.5.219/phonebook/grandstream.xml /var/www/10.0.5.219/phonebook/yealink.xml /var/www/10.0.5.219/phonebook/rtx.csv
