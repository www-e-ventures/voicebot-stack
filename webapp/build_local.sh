cd ~/ev/chatbot
docker compose build --no-cache api
docker compose up -d api
docker logs -f voicebot_api
