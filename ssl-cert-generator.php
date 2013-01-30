<?php


/**
 * Generates a self-signed PEM certificate
 */
function createSslCert($pemFile, $pemPassphrase, $pemDn) {
    // Create private key
    $privkey = openssl_pkey_new();

    // Create and sign CSR
    $cert = openssl_csr_new($pemDn, $privkey);
    $cert = openssl_csr_sign($cert, null, $privkey, 365);

    // Generate PEM file
    $pem = array();
    openssl_x509_export($cert, $pem[0]);
    openssl_pkey_export($privkey, $pem[1], $pemPassphrase);

    // Save PEM file
    file_put_contents($pemFile, implode($pem));
    chmod($pemFile, 0600);
}

$pemPassphrase = "42 is not a legitimate passphrase";
$pemFile = __DIR__ . "/mycert.pem";
$pemDn = array(
    "countryName" => "US",                          // country name
    "stateOrProvinceName" => "SC",                  // state or province name
    "localityName" => "Myrtle Beach",               // your city name
    "organizationName" => "N/A",                    // company name
    "organizationalUnitName" => "N/A",              // department name
    "commonName" => "aerys",                        // full hostname
    "emailAddress" => "email@example.com"           // email address
);

if (!file_exists($pemFile)) {
    createSslCert($pemFile, $pemPassphrase, $pemDn);
}
