--
-- Create Basket Step Table
--
CREATE TABLE `basket_step` (
   `Step_ID` int(11) NOT NULL,
   `basket_idfs` int(11) NOT NULL,
   `label` varchar(255) NOT NULL,
   `step_key` varchar(100) NOT NULL,
   `comment` text NOT NULL,
   `date_created` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `basket_step`
    ADD PRIMARY KEY (`Step_ID`);

ALTER TABLE `basket_step`
    MODIFY `Step_ID` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

--
-- Add new Tab to Basket
--
INSERT INTO `core_form_tab` (`Tab_ID`, `form`, `title`, `subtitle`, `icon`, `counter`, `sort_id`, `filter_check`, `filter_value`) VALUES
('basket-steps', 'basket-single', 'Steps', 'User Interaction', 'fas fa-list-ol', '', '1', '', '');

--
-- Add new partial
--
INSERT INTO `core_form_field` (`Field_ID`, `type`, `label`, `fieldkey`, `tab`, `form`, `class`, `url_view`, `url_list`, `show_widget_left`, `allow_clear`, `readonly`, `tbl_cached_name`, `tbl_class`, `tbl_permission`) VALUES
(NULL, 'partial', 'Steps', 'basket_steps', 'basket-steps', 'basket-single', 'col-md-12', '', '', '0', '1', '0', '', '', '');

INSERT INTO `settings` (`settings_key`, `settings_value`) VALUES ('shop-email-subject-receipt', 'Ihre Bestellung im Shop');