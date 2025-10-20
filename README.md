# File Layer Upload API

- This API provides functionality for managing geospatial file layers by integrating with AWS S3 and GeoServer.
When invoked, the API downloads the specified file layers stored in an AWS S3 bucket and saves them into the GeoServer data directory for further use or visualization.

- The API currently supports the following geospatial file types:

    1. SHP (Shapefile) – A widely used vector data format for geographic information system (GIS) software.

    2. KML (Keyhole Markup Language) – An XML-based format used to represent geographic data for applications such as Cesium.

    3. AIC (Aerial Image Compare) – Aerial imagery or raster data used for visual comparison or overlay with existing map layers.

# Setup
- To use this API, you need to have an AWS account and an S3 bucket.
- Clone this app inside your web server's root directory.
- Create a new file named `.env` in the root directory and add the following variables:
```
# AWS
AWS_BUCKET=
AWS_CLIENT=
AWS_SECRET=

# GEOSERVER
GEOSERVERDOMAIN=
GEOSERVERPW=

# DB
DB_SERVERNAME=
DB_NAME=
DB_USERNAME=
DB_PASSWORD=
```
- Run ```composer install``` to install the required dependencies.
- Create `S3_FILES` folder inside `C:\inetpub\wwwroot\Data` directory.
```
S3_FILES
├── Geoserver
    ├── Shapefile
    ├── AIC
└── KML
```

# Usage

- Send a POST request to the API endpoint with the following parameters:
  - `op`: The operation to perform (value: `download`).
  - `fileType`: The type of file layer to upload (SHP, KML, AIC).
  - `projectName`: The name of the project to which the file layer belongs.
  - `email`: The email of the user who uploaded the file layer. Take note: The user role must be a `Project Manager` or `Project Monitor`.