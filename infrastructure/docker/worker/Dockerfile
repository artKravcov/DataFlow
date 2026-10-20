FROM node:24.20.0-alpine3.24

WORKDIR /app

COPY worker/package.json worker/package-lock.json ./
RUN npm ci

COPY worker/ ./
COPY contracts/ /contracts/
RUN npm run build

CMD ["npm", "run", "start"]