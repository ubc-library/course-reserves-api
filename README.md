Library Online Course Reserves - API
-------------------------------------
This project is the API part of the Library Online Course Reserves System.

Prerequisites & Project Configuration
----------------------
### You will need
- An SMTP Server
- An IDBox service (Identity Management)
- A webserver on the installation server, e.g. nginx
- A BlackBoard Instance
- A Staff Directory (API enabled, from which entities such as librarians, branches and course codes may be read)
- A running instance of `course-reserves-admin` see https://github.com/ubc-library/course-reserves-admin
- A running instance of `course-reserves-connect`

### Rename `distribution` files
- `mv ./config.inc.php-dist ./config.inc.php`
- `mv ./setup/EXAMPLE.libconnect.inc.php ./setup/libconnect.inc.php`


Docker - Quick Start to App
---------------------------

## Build The Image
- cd to the root folder of the application: `cd CODE_ROOT`
- build the docker image, lets name it 'licr' and tag it 'v1.5.0' `sudo docker build -t licr:v1.5.0 .`
- this image is now available on the server, `sudo docker images`

## Run a Named Instance
- for this step, you need to know which instance you want to run (e.g. dev-licr, or dev-rm-licr)
- reference the file resources/docker-host/nginx/nginx.conf for a listing of expected instances, and the port it is bound to, for this example, we are using `dev-licr`, which is bound to `8090`
- run your container `sudo docker run -d --name dev-licr -p 127.0.0.1:8090:80 -it licr:v1.5.0`
- the --name, set with the flag `--name dev-licr` can be used to reference this container in all subsequent commands, as seen below
- shell into the container to access applications `sudo docker exec -it dev-licr /bin/bash`

## Start licr
- shell into the container to access applications `sudo docker exec -it dev-licr /bin/bash`
- start php `service php5-fpm restart`
- change to code folder `cd /usr/local/licr`
- install php dependencies via composer `composer update`
- change your server name to the correct site (temporary fix) `vim /etc/nginx/sites-available/licr`
- start the web server, `service nginx restart`

## Maintenance - make your life easier
- on the host, `sudo docker inspect dev-licr`
- look for the `Mounts` entry, these are volumes in the container that are accessible on the host machine
- we typically define two mounts in the Dockerfile, the application logs (`Destination: /var/log/licr`), and the nginx logs (`Destination: /var/log/nginx`)
- each entry states where on the host machine the container folder can be accessed, e.g. `cd /var/lib/docker/volumes/3779b352c1da6b0c09f0752f3b/_data` to access the container volume `/var/logs/nginx`
- create a folder, if not exists, in the host `/usr/local/docker-instances`, for this named instance, `mkdir /usr/local/docker-instances/dev-licr`
- for each mount, create a symlink in this folder, e.g. `ln -s /var/lib/docker/volumes/3779b352c1da6b0c09f0752f3b/_data /usr/local/docker-instances/dev-licr/nginx-logs`
- you can now access these logs in a more memorable format, e.g., `tail -f -n 600 /usr/local/docker-instances/dev-licr/nginx-logs/error.log`, rather than the unmemorable docker path

## Cleanup
- get list of containers `sudo docker ps -a`
- stop the container that you just started `sudo docker stop CONTAINER_ID`
- using our example in startup, stop by `sudo docker stop dev-licr`
- remove all stopped containers `sudo docker rm $(sudo docker ps -a -q)`
- list docker images `sudo docker images`
- remove any unused images `sudo docker rmi IMAGE_ID` OR `sudo docker rmi IMAGE_NAME:TAG` (if no tag, defaults to 'latest' tag, so IMAGE_NAME:latest)