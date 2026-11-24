FROM nginx:stable-alpine
COPY public /srv/app/public
RUN mkdir -p /srv/app/storage/app/public /srv/admin \
    && ln -s /srv/app/storage/app/public /srv/app/public/storage
