<!DOCTYPE html>
<html lang="en">

<head>
    <?php $this->load->view("admin/_partials/head.php") ?>
</head>

<body id="page-top">
    <?php $this->load->view("admin/_partials/navbar.php")?>

  <div id="wrapper">

    <!-- Sidebar -->
    <?php $this->load->view("admin/_partials/sidebar.php")?>

    <div id="content-wrapper">

      <div class="container-fluid">

        <!-- Breadcrumbs-->
        <?php $this->load->view("admin/_partials/breadcrumb.php")?>

        <!-- Icon Cards-->
        

        <!-- Area Chart Example-->
       
        <!-- DataTables Example -->

      </div>
      <!-- /.container-fluid -->

      <!-- Sticky Footer -->
      <?php $this->load->view("admin/_partials/footer.php")?>
    <!-- /.content-wrapper -->

  </div>
  <!-- /#wrapper -->

  <!-- Scroll to Top Button-->
  <?php $this->load->view("admin/_partials/scrolltop.php")?>

  <!-- Logout Modal-->
  <?php $this->load->view("admin/_partials/modal.php")?>
  

  <!-- link js -->
  <?php $this->load->view("admin/_partials/js.php")?>

</body>

</html>
