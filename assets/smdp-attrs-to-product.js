jQuery(document).ready(function ($) {
  const categoryBox = $("#product_catchecklist");

  categoryBox.on("change", "input[type=checkbox]", function () {
    let cat_ids = [];
    categoryBox.find("input[type=checkbox]:checked").each(function () {
      cat_ids.push($(this).val());
    });

    $.post(
      smdpAjax.ajax_url,
      {
        action: "smdp_sync_product_attrs",
        nonce: smdpAjax.nonce,
        post_id: smdpAjax.post_id,
        cat_ids: cat_ids
      },
      function (response) {
        if (response.success) {
          console.log("✅ " + response.data.message);

          // Replace the Attributes meta box with fresh content
          let newBox = $(response.data.html).find("#product_attributes");
          $("#product_attributes").replaceWith(newBox);
        } else {
          console.warn("⚠️ " + response.data.message);
        }
      }
    );
  });
});
