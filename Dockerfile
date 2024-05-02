FROM php:8.2-cli
ENV docuwiki /docuwiki
RUN mkdir $docuwiki
WORKDIR $docuwiki
ADD . $docuwiki
