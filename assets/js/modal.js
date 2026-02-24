let modalOutsideClickBound = false;

// Contact form submission (unchanged)
document
  .getElementById('contact-form')
  ?.addEventListener('submit', function (event) {
    event.preventDefault(); // Prevent the default form submission

    // Show the modal
    var modal = document.getElementById('emailSentModal');
    if (modal) {
      modal.style.display = 'block';

      // Close the modal when the user clicks on the close button
      var closeButton = document.getElementsByClassName('close-button')[0];
      if (closeButton) {
        closeButton.onclick = function () {
          modal.style.display = 'none';
        };
      }

      // Close the modal when the user clicks anywhere outside of the modal
      if (!modalOutsideClickBound) {
        window.addEventListener('click', function (event) {
          if (event.target == modal) {
            modal.style.display = 'none';
          }
        });
        modalOutsideClickBound = true;
      }

      // Send form data using EmailJS
      emailjs.sendForm('service_j5oer3j', 'template_q6h7j1h', this).then(
        function (response) {
          void response;
          // Reset the form
          event.target.reset();
        },
        function (error) {
          console.error('EmailJS send failed', error);
        },
      );
    }
  });
