FROM alpine:3.24.1
RUN apk add --no-cache dovecot openssl \
    && adduser -D -u 1000 vmail \
    && mkdir -p /srv/mail/support \
    && chown -R vmail:vmail /srv/mail
COPY dovecot.conf /etc/dovecot/dovecot.conf
RUN printf '%s\n' 'support:{PLAIN}test-mail-password' > /etc/dovecot/passwd
COPY start /usr/local/bin/start
RUN chmod +x /usr/local/bin/start
CMD ["/usr/local/bin/start"]
