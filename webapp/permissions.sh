#cd /opt/chatbot/webapp
sudo chgrp -R www-data bootstrap storage
sudo find bootstrap storage -type d -exec chmod 2775 {} \;
sudo find bootstrap storage -type f -exec chmod 664 {} \;
