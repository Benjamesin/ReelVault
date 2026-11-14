<?php
require_once __DIR__ . '/config.php';
if (!REQUIRE_CAPTCHA) {
    header('Location: landingPage.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IP Fetch and Redirect</title>
  <style>
    @keyframes rainbow {
      0% {
        background: linear-gradient(to right, red, orange, yellow, green, blue, indigo, violet);
      }
      14% {
        background: linear-gradient(to right, orange, yellow, green, blue, indigo, violet, red);
      }
      28% {
        background: linear-gradient(to right, yellow, green, blue, indigo, violet, red, orange);
      }
      42% {
        background: linear-gradient(to right, green, blue, indigo, violet, red, orange, yellow);
      }
      56% {
        background: linear-gradient(to right, blue, indigo, violet, red, orange, yellow, green);
      }
      70% {
        background: linear-gradient(to right, indigo, violet, red, orange, yellow, green, blue);
      }
      84% {
        background: linear-gradient(to right, violet, red, orange, yellow, green, blue, indigo);
      }
      100% {
        background: linear-gradient(to right, red, orange, yellow, green, blue, indigo, violet);
      }
    }

    @keyframes bounce {
      0% {
        transform: translateY(0);
      }
      50% {
        transform: translateY(-20px);
      }
      100% {
        transform: translateY(0);
      }
    }

    h2 {
      font-family: Arial, sans-serif;
      font-size: 48px;
      color: white;
      text-align: center;
      margin-top: 100px;
      animation: bounce 1s infinite; 
    }

    .container {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100vh;
      background-color: black;
    }

    h1, p {
      color: white;
      font-size: 24px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>No Robots Allowed</h1>
    <h2>WELCOME!</h2>
  </div>
  <script>
    async function getServerIP() {
      try {
        const response = await fetch('get_external_ip.php');
        const ip = await response.text();
        return ip;
      } catch (error) {
        console.error('Error fetching the server IP address:', error);
        return null;
      }
    }

    async function saveIPToFile(ip) {
      try {
        const response = await fetch('save_ip.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ ip: ip })
        });

        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
      } catch (error) {
        console.error('Error saving IP to file:', error);
      }
    }

    (async () => {
      // Optional logging/saving step
      const serverIP = await getServerIP();
      if (serverIP) {
        await saveIPToFile(serverIP);
      }

      // ✅ RELATIVE REDIRECT: Preserves Tailscale IP or MagicDNS in the URL bar
      setTimeout(function() {
        window.location.href = 'verify.php';
      }, 1000);
    })();
  </script>
</body>
</html>
